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
 * Levels 1–2 read the files on disk: the candidate is written in place for them and the original
 * is restored afterwards (a backup is kept in the cache until then).
 *
 * @internal
 */
final class Verifier
{
    /** @var \Closure(string): void */
    private \Closure $log;

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
        $original = (string) \file_get_contents((string) $file);
        $relative = $this->project->relative($file);
        $work = $this->cacheDir->join('tmp');
        FS::mkdir((string) $work);
        $notes = [];

        $syntax = (new SyntaxChecker($this->php, $work))->check($candidate);
        if ($syntax !== null) {
            return new CandidateReport([], $syntax);
        }

        $changed = $keys ?? $this->changedFunctions($original, $candidate, $relative, $notes);
        $runner = $withTests ? TestRunnerFactory::create($this->project, $this->tests, $this->php, $work) : null;
        $phpstan = [];
        $staticErrors = [];
        $testResult = null;
        $coverage = null;
        if ($withTests) {
            [$phpstan, $testResult, $coverage, $red, $staticErrors] = $this->levelsOneAndTwo($file, $original, $candidate, $runner, $work, $notes, $changed, $relative);
            if ($red !== null) {
                # Tests red on the original code prove nothing about a change.
                return new CandidateReport([], null, [], $runner?->name(), $red, $notes);
            }
        }

        $autoload = $this->project->root->join('vendor/autoload.php');
        $tester = new DiffTester($this->php, $this->verification, $work, $this->cacheDir->join('corpus'));
        $results = [];
        foreach ($changed as $key) {
            ($this->log)("Differential test of {$key}");
            $verdict = $tester->verify(new DiffTask($key, $file, $relative, $original, $candidate, $autoload->isFile() ? $autoload : null, staticErrors: $staticErrors));
            $tests = [];
            if ($coverage !== null) {
                [$from, $to] = $this->lines($original, $key, $relative);
                $tests = $coverage->tests((string) $file, $from, $to);
            }

            $results[] = $this->decide($key, $verdict, $tests, $testResult?->success ?? false, $runner, $original, $relative, $counterexamples);
        }

        return new CandidateReport($results, null, $phpstan, $runner?->name(), $testResult, $notes);
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
     * @param list<string> $notes
     * @param list<non-empty-string> $changed
     * @param non-empty-string $relative
     * @return array{list<array{file: string, message: string, identifier: string, line: int}>, ?TestResult, ?CoverageMap, ?TestResult, list<array{file: string, message: string, identifier: string, line: int}>} New
     *         PHPStan errors, tests on the candidate, coverage map, tests on the original when they are red,
     *         PHPStan errors of the original file (they mark dead branches).
     */
    private function levelsOneAndTwo(Path $file, string $original, string $candidate, ?TestRunnerAdapter $runner, Path $work, array &$notes, array $changed, string $relative): array
    {
        $phpstan = new PhpStanRunner($this->project, $this->php, $this->commands->phpstan, $this->phpTarget, $work);
        $phpstan->available() or $notes[] = 'PHPStan is not installed (commands.phpstan): the static check is skipped.';
        $runner === null and $notes[] = 'No test runner found (tests.runner): the project\'s tests are not run.';

        $before = $phpstan->available() ? $phpstan->analyse([$file]) : null;
        $real = \realpath((string) $file);
        $own = \array_values(\array_filter(
            $before->errors ?? [],
            static fn(array $e): bool => $e['file'] === (string) $file || ($real !== false && \realpath($e['file']) === $real),
        ));
        $baseline = null;
        $coverage = null;
        if ($runner !== null) {
            ($this->log)("Project tests on the original code ({$runner->name()})");
            $baseline = $runner->runAll();
            if (!$baseline->success) {
                $notes[] = 'The project\'s tests fail on the original code: fix them first. Failed: ' . \implode(', ', \array_slice($baseline->failed, 0, 10));

                return [[], null, null, $baseline, $own];
            }

            $coverage = $runner->collectCoverageMap();
            $coverage === null and $notes[] = 'No coverage map of the project\'s tests (Xdebug or pcov in php.binary): all tests are run.';
        }

        $backup = $work->join('backup-' . \bin2hex(\random_bytes(4)) . '.php');
        \file_put_contents((string) $backup, $original);
        try {
            \file_put_contents((string) $file, $candidate);
            $new = [];
            if ($before !== null) {
                ($this->log)('PHPStan on the changed code');
                $new = PhpStanResult::newErrors($before, $phpstan->analyse([$file]));
            }

            $after = null;
            if ($runner !== null && $coverage !== null) {
                # Only the tests that execute a changed function can see the change.
                $selected = [];
                foreach ($changed as $key) {
                    [$from, $to] = $this->lines($original, $key, $relative);
                    \array_push($selected, ...$coverage->tests((string) $file, $from, $to));
                }

                $selected = \array_values(\array_unique($selected));
                ($this->log)('Project tests on the changed code: ' . \count($selected) . ' that execute the changed functions');
                $after = $runner->runFiltered($selected);
            } elseif ($runner !== null) {
                ($this->log)('Project tests on the changed code');
                $after = $runner->runAll();
            }

            return [$new, $after, $coverage, null, $own];
        } finally {
            \file_put_contents((string) $file, $original);
            FS::removeFile($backup);
        }
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
