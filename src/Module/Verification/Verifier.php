<?php

declare(strict_types=1);

namespace Opmin\Module\Verification;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Config\Schema;
use Opmin\Module\Lint\PhpStanResult;
use Opmin\Module\Lint\PhpStanRunner;
use Opmin\Module\Lint\SyntaxChecker;
use Opmin\Module\Opcode\Locate\CodeUnit;
use Opmin\Module\Opcode\Locate\FunctionLocator;
use Opmin\Module\Opcode\Locate\LocateException;
use Opmin\Module\Opcode\Locate\UnitKind;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Project\Project;
use Opmin\Module\Tests\CommandAdapter;
use Opmin\Module\Tests\CoverageMap;
use Opmin\Module\Tests\TestResult;
use Opmin\Module\Tests\TestRunnerAdapter;
use Opmin\Module\Tests\TestRunnerFactory;
use Opmin\Module\Verification\Target\TargetLocator;
use Opmin\Module\Verification\Target\UnsupportedTarget;

/**
 * Verifies a changed version of a file (brief, Модуль 2): level 1 — `php -l` and no new PHPStan
 * errors; level 2 — the project's tests (red on the original code: verification stops); level 3 —
 * a differential test of every changed function. A function is accepted when the differential
 * test proves it, or when it is unverified but executed by passing project tests, or with
 * `verification.allow_unverified`.
 *
 * Levels 1–2 read the files on disk: the candidates are written in place for them and the
 * originals are restored afterwards (backups are kept in the cache until then).
 *
 * One instance serves a whole optimization run: the project's tests run on the original code and
 * the coverage map is collected once, at the first check with tests; later checks look up the
 * tests of a function by its key in the file as it was then (keys survive line shifts).
 *
 * @internal
 */
final class Verifier
{
    /** @var \Closure(string): void */
    private \Closure $log;

    private bool $baselineDone = false;

    /** Tests on the original code when they are red. */
    private ?TestResult $red = null;

    private ?CoverageMap $coverage = null;

    /** @var array<non-empty-string, string> File => its content when the coverage map was collected. */
    private array $coverageBase = [];

    /** @var list<string> Notes of the baseline, repeated in every report. */
    private array $baselineNotes = [];

    private ?TestRunnerAdapter $runner = null;
    private bool $runnerResolved = false;

    /**
     * @param non-empty-string|null $phpTarget
     * @param \Closure(string): void|null $log Progress messages.
     */
    public function __construct(
        private readonly Project $project,
        private readonly PhpBinary $php,
        private readonly Schema\Verification $verification,
        private readonly Schema\Commands $commands,
        private readonly Schema\Tests $tests,
        private readonly Path $cacheDir,
        private readonly ?string $phpTarget,
        ?\Closure $log = null,
    ) {
        $this->log = $log ?? static function (string $message): void {};
    }

    /**
     * @param Path $file Absolute; its content on disk is the original.
     * @param list<non-empty-string>|null $keys Functions to verify; null — every changed one.
     * @param bool $withTests Run levels 1–2 (PHPStan, the project's tests) too.
     * @param Path|null $counterexamples Directory for counterexample tests; null — not written.
     */
    public function verify(Path $file, string $candidate, ?array $keys = null, bool $withTests = false, ?Path $counterexamples = null): CandidateReport
    {
        return $this->verifyAll([[$file, $candidate]], $keys === null ? null : [(string) $file => $keys], $withTests, $counterexamples);
    }

    /**
     * Verifies several changed files of one step together: one PHPStan run, one run of the tests,
     * and the differential test of a function sees the other changed files in their changed version.
     *
     * @param list<array{Path, string}> $changes [absolute file whose content on disk is the original, candidate].
     * @param array<string, list<non-empty-string>>|null $keys File => functions to verify; null — every changed one.
     */
    public function verifyAll(array $changes, ?array $keys = null, bool $withTests = false, ?Path $counterexamples = null): CandidateReport
    {
        $work = $this->cacheDir->join('tmp');
        FS::mkdir((string) $work);
        $notes = [];
        /** @var list<array{Path, non-empty-string, string, string, list<non-empty-string>}> $files */
        $files = [];
        foreach ($changes as [$file, $candidate]) {
            $original = (string) \file_get_contents((string) $file);
            $relative = $this->project->relative($file);
            $syntax = (new SyntaxChecker($this->php, $work))->check($candidate);
            if ($syntax !== null) {
                return new CandidateReport([], \count($changes) > 1 ? "{$relative}: {$syntax}" : $syntax);
            }

            $changed = $keys === null ? $this->changedFunctions($original, $candidate, $relative, $notes) : ($keys[(string) $file] ?? []);
            $files[] = [$file, $relative, $original, $candidate, $changed];
        }

        $runner = $withTests ? $this->runner($work) : null;
        $phpstan = [];
        $staticErrors = [];
        $testResult = null;
        $testsOf = [];
        if ($withTests) {
            [$phpstan, $testResult, $red, $staticErrors, $testsOf] = $this->levelsOneAndTwo($files, $runner, $work, $notes);
            if ($red !== null) {
                # Tests red on the original code prove nothing about a change.
                return new CandidateReport([], null, [], $runner?->name(), $red, $notes);
            }
        }

        $autoload = $this->project->root->join('vendor/autoload.php');
        $tester = new DiffTester($this->php, $this->verification, $work, $this->cacheDir->join('corpus'));
        $results = [];
        foreach ($files as [$file, $relative, $original, $candidate, $changed]) {
            $others = [];
            foreach ($files as [$otherFile, , $otherOriginal, $otherCandidate]) {
                (string) $otherFile === (string) $file or $others[(string) $otherFile] = [$otherOriginal, $otherCandidate];
            }

            foreach ($changed as $key) {
                ($this->log)("Differential test of {$key}");
                $verdict = $tester->verify(new DiffTask(
                    $key,
                    $file,
                    $relative,
                    $original,
                    $candidate,
                    $autoload->isFile() ? $autoload : null,
                    $others,
                    $staticErrors[(string) $file] ?? [],
                ));
                $results[] = $this->decide($key, $verdict, $testsOf[(string) $file][$key] ?? [], $testResult?->success ?? false, $runner, $original, $relative, $counterexamples);
            }
        }

        return new CandidateReport($results, null, $phpstan, $runner?->name(), $testResult, $notes);
    }

    /**
     * The project's tests on the code as it is on disk now (the final check of a run); null without a runner.
     */
    public function runAllTests(): ?TestResult
    {
        $work = $this->cacheDir->join('tmp');
        FS::mkdir((string) $work);

        return $this->runner($work)?->runAll();
    }

    /**
     * Name of the project's test runner; null without one.
     */
    public function runnerName(): ?string
    {
        $work = $this->cacheDir->join('tmp');
        FS::mkdir((string) $work);

        return $this->runner($work)?->name();
    }

    private static function source(string $code, CodeUnit $unit): string
    {
        $node = $unit->node;
        if ($node === null) {
            return '';
        }

        return \substr($code, $node->getStartFilePos(), $node->getEndFilePos() - $node->getStartFilePos() + 1);
    }

    /**
     * @param list<array{Path, non-empty-string, string, string, list<non-empty-string>}> $files
     * @param list<string> $notes
     * @return array{list<array{file: string, message: string, identifier: string, line: int}>, ?TestResult, ?TestResult, array<string, list<array{file: string, message: string, identifier: string, line: int}>>, array<string, array<string, list<non-empty-string>>>}
     *         New PHPStan errors, tests on the candidates, tests on the original when they are red,
     *         PHPStan errors of each original file (they mark dead branches), tests of each function.
     */
    private function levelsOneAndTwo(array $files, ?TestRunnerAdapter $runner, Path $work, array &$notes): array
    {
        $phpstan = new PhpStanRunner($this->project, $this->php, $this->commands->phpstan, $this->phpTarget, $work);
        $phpstan->available() or $notes[] = 'PHPStan is not installed (commands.phpstan): the static check is skipped.';
        $runner === null and $notes[] = 'No test runner found (tests.runner): the project\'s tests are not run.';

        $paths = \array_map(static fn(array $f): Path => $f[0], $files);
        $before = $phpstan->available() ? $phpstan->analyse($paths) : null;
        $own = [];
        foreach ($paths as $file) {
            $real = \realpath((string) $file);
            $own[(string) $file] = \array_values(\array_filter(
                $before->errors ?? [],
                static fn(array $e): bool => $e['file'] === (string) $file || ($real !== false && \realpath($e['file']) === $real),
            ));
        }

        if ($runner !== null) {
            $this->baseline($runner);
            if ($this->red !== null) {
                \array_push($notes, ...$this->baselineNotes);

                return [[], null, $this->red, $own, []];
            }

            \array_push($notes, ...$this->baselineNotes);
        }

        # Tests of every changed function, looked up in the file as it was when the map was made.
        $testsOf = [];
        $selected = [];
        foreach ($files as [$file, $relative, $original, , $changed]) {
            $this->coverage === null || isset($this->coverageBase[(string) $file]) or $this->coverageBase[(string) $file] = $original;
            foreach ($changed as $key) {
                $tests = [];
                if ($this->coverage !== null) {
                    [$from, $to] = $this->lines($this->coverageBase[(string) $file] ?? $original, $key, $relative);
                    $tests = $this->coverage->tests((string) $file, $from, $to);
                }

                $testsOf[(string) $file][$key] = $tests;
                \array_push($selected, ...$tests);
            }
        }

        $backups = [];
        try {
            foreach ($files as [$file, , $original, $candidate]) {
                $backup = $work->join('backup-' . \bin2hex(\random_bytes(4)) . '.php');
                \file_put_contents((string) $backup, $original);
                $backups[] = [$file, $original, $backup];
                FS::replace((string) $file, $candidate);
            }

            $new = [];
            if ($before !== null) {
                ($this->log)('PHPStan on the changed code');
                $new = PhpStanResult::newErrors($before, $phpstan->analyse($paths));
            }

            $after = null;
            if ($runner !== null && $this->coverage !== null) {
                # Only the tests that execute a changed function can see the change.
                $selected = \array_values(\array_unique($selected));
                ($this->log)('Project tests on the changed code: ' . \count($selected) . ' that execute the changed functions');
                $after = $runner->runFiltered($selected);
            } elseif ($runner !== null) {
                ($this->log)('Project tests on the changed code');
                $after = $runner->runAll();
            }

            return [$new, $after, null, $own, $testsOf];
        } finally {
            foreach ($backups as [$file, $original, $backup]) {
                FS::replace((string) $file, $original);
                FS::removeFile($backup);
            }
        }
    }

    /**
     * The project's tests on the original code and the coverage map, once per instance.
     */
    private function baseline(TestRunnerAdapter $runner): void
    {
        if ($this->baselineDone) {
            return;
        }

        $this->baselineDone = true;
        ($this->log)("Project tests on the original code ({$runner->name()})");
        $baseline = $runner->runAll();
        if (!$baseline->success) {
            $this->red = $baseline;
            $this->baselineNotes[] = 'The project\'s tests fail on the original code: fix them first. Failed: ' . \implode(', ', \array_slice($baseline->failed, 0, 10));

            return;
        }

        $this->coverage = $runner->collectCoverageMap();
        $this->coverage === null and $this->baselineNotes[] = 'No coverage map of the project\'s tests (Xdebug or pcov in php.binary): all tests are run.';
    }

    private function runner(Path $work): ?TestRunnerAdapter
    {
        if (!$this->runnerResolved) {
            $this->runnerResolved = true;
            $this->runner = TestRunnerFactory::create($this->project, $this->tests, $this->php, $work);
        }

        return $this->runner;
    }

    /**
     * @param list<non-empty-string> $tests
     * @param non-empty-string $key
     * @param non-empty-string $relative
     */
    private function decide(string $key, Verdict $verdict, array $tests, bool $testsPassed, ?TestRunnerAdapter $runner, string $original, string $relative, ?Path $counterexamples): FunctionResult
    {
        if ($verdict->status === VerdictStatus::Mismatch) {
            $test = $counterexamples === null || $verdict->counterexample === null
                ? null
                # Without a known runner the counterexample is a plain PHP script.
                : $this->writeCounterexample($key, $verdict->counterexample, $runner ?? new CommandAdapter($this->project, 'php'), $original, $relative, $counterexamples);

            return new FunctionResult($key, 'rejected', $verdict, 'differential test: ' . $verdict->reason, $tests, $test);
        }

        if ($verdict->accepted()) {
            return new FunctionResult($key, 'diff-tested', $verdict, '', $tests);
        }

        if ($tests !== [] && $testsPassed) {
            return new FunctionResult($key, 'tests', $verdict, 'covered by ' . \count($tests) . ' passing test(s); ' . $verdict->reason, $tests);
        }

        if ($this->verification->allowUnverified && $verdict->status !== VerdictStatus::Skipped) {
            return new FunctionResult($key, 'unverified', $verdict, $verdict->reason, $tests);
        }

        return new FunctionResult($key, 'rejected', $verdict, 'not proven: ' . $verdict->reason, $tests);
    }

    /**
     * @param non-empty-string $key
     * @param non-empty-string $relative
     */
    private function writeCounterexample(string $key, Counterexample $counterexample, TestRunnerAdapter $runner, string $original, string $relative, Path $dir): ?string
    {
        try {
            $target = (new TargetLocator())->locate($original, $key, $relative);
        } catch (UnsupportedTarget) {
            return null;
        }

        $name = 'Opmin' . (string) \preg_replace('/[^A-Za-z0-9]+/', '', \ucwords(\str_replace(['\\', ':', '{', '}', '$'], ' ', $key))) . 'CounterexampleTest';
        FS::mkdir((string) $dir);
        $path = $dir->join($name . '.php');
        /** @var non-empty-string $name */
        \file_put_contents((string) $path, (new CounterexampleRenderer())->render($target, $counterexample, $runner, $name));
        \file_put_contents((string) $dir->join($name . '.json'), (string) \json_encode(
            ['function' => $key, 'file' => $relative] + $counterexample->toArray(),
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION,
        ));

        return (string) $path;
    }

    /**
     * Keys of functions whose code differs between the versions (or that exist in one only).
     *
     * @param non-empty-string $relative
     * @param list<string> $notes
     * @return list<non-empty-string>
     */
    private function changedFunctions(string $original, string $candidate, string $relative, array &$notes): array
    {
        $before = $this->units($original, $relative);
        $after = $this->units($candidate, $relative);
        $changed = [];
        foreach ($after as $key => $code) {
            if (!isset($before[$key])) {
                $notes[] = "`{$key}` is new: it has no original behavior to compare with.";
                continue;
            }

            $before[$key] === $code or $changed[] = $key;
        }

        foreach (\array_keys(\array_diff_key($before, $after)) as $key) {
            $notes[] = "`{$key}` is removed in the candidate.";
            $changed[] = $key;
        }

        return $changed;
    }

    /**
     * @param non-empty-string $relative
     * @return array<non-empty-string, string> Key => source code of the function.
     */
    private function units(string $code, string $relative): array
    {
        try {
            $units = (new FunctionLocator())->locate($code, $relative . '::<main>');
        } catch (LocateException) {
            return [];
        }

        $result = [];
        foreach ($units as $unit) {
            if ($unit->kind === UnitKind::Main || $unit->abstract || $unit->node === null) {
                continue;
            }

            $result[$unit->key] = self::source($code, $unit);
        }

        return $result;
    }

    /**
     * @return array{int, int}
     */
    private function lines(string $code, string $key, string $relative): array
    {
        try {
            foreach ((new FunctionLocator())->locate($code, ($relative === '' ? 'x' : $relative) . '::<main>') as $unit) {
                if ($unit->key === $key) {
                    return [$unit->line, $unit->endLine];
                }
            }
        } catch (LocateException) {
        }

        return [0, -1];
    }
}
