<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize;

use Internal\Path;
use Opmin\Module\Analysis\Flag;
use Opmin\Module\Analysis\IgnoreMarks;
use Opmin\Module\Analysis\ReferenceIndex;
use Opmin\Module\Analysis\Restriction;
use Opmin\Module\Config\Schema;
use Opmin\Module\Lint\SyntaxChecker;
use Opmin\Module\Opcode\CountResult;
use Opmin\Module\Opcode\FunctionCount;
use Opmin\Module\Opcode\OpcodeCounter;
use Opmin\Module\Optimize\Rector\RectorRunner;
use Opmin\Module\Optimize\Rector\RuleSpec;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Project\Project;
use Opmin\Module\Verification\CandidateReport;
use Opmin\Module\Verification\Verifier;

/**
 * Stage A of `opmin optimize` (brief, «Модуль 3»): rules one by one, greedily, in passes until a
 * pass changes nothing (at most `rector.max_passes`).
 *
 * One step: Rector applies the rule to all target files → the formatter runs on the changed files →
 * `php -l` → opcodes are counted → each changed function is kept only when it saves opcodes, passes
 * the readability thresholds and the signature rules, and the user did not exclude it; the others are
 * taken back from the current version (a change outside functions makes the file all-or-nothing) →
 * the rest is verified on all three levels, rejected functions are taken back and the rest verified
 * again → the step is written (a commit in git).
 *
 * Between steps the files on disk are the current accepted version: candidates are written only for
 * the time of a count or a check and restored right after.
 *
 * @internal
 */
final class Optimizer
{
    /** @var array<non-empty-string, Path> Relative => absolute. */
    private array $paths = [];

    /** @var array<non-empty-string, string> Relative => the current accepted content. */
    private array $current = [];

    /** @var array<non-empty-string, array<non-empty-string, FunctionCount>> Relative => key => count of the current content. */
    private array $counts = [];

    /** @var array<non-empty-string, true> Files the formatter would change before any step: not formatted. */
    private array $unformatted = [];

    /** @var array<string, true> What the verifier could not check, once per run. */
    private array $verifierNotes = [];

    private readonly Readability $readability;
    private readonly SignatureGate $signatures;

    /** @var \Closure(string): void */
    private readonly \Closure $log;

    /**
     * @param \Closure(string): void|null $log Progress messages.
     */
    public function __construct(
        private readonly Project $project,
        private readonly PhpBinary $php,
        private readonly Workspace $workspace,
        private readonly OpcodeCounter $counter,
        private readonly ?ReferenceIndex $references,
        private readonly RectorRunner $rector,
        private readonly Formatter $formatter,
        private readonly Verifier $verifier,
        private readonly Schema\Rector $rectorConfig,
        Schema\Readability $readability,
        Schema\Signatures $signatures,
        private readonly Schema\Ignore $ignore,
        private readonly Path $workDir,
        bool $allowPublicSignatures = false,
        ?\Closure $log = null,
    ) {
        $this->readability = new Readability($readability);
        $this->signatures = new SignatureGate($signatures, $allowPublicSignatures);
        $this->log = $log ?? static function (string $message): void {};
    }

    /**
     * @param list<Path> $files Absolute target files.
     * @param list<RuleSpec> $rules In the order of application.
     */
    public function run(array $files, array $rules): RunReport
    {
        foreach ($files as $file) {
            $relative = $this->project->relative($file);
            $this->paths[$relative] = $file;
            $this->current[$relative] = (string) \file_get_contents((string) $file);
            $this->workspace->git() or $this->workspace->backup($file);
        }

        $baseline = $this->countCurrent();
        $report = new RunReport(self::total($this->counts), (string) $this->workspace->runDir);
        $this->writeJson('00-baseline.json', $baseline);
        foreach ($baseline->errors as $file => $error) {
            $report->notes[] = "{$file} cannot be counted and is not changed: {$error}";
        }

        $this->detectUnformatted($report);
        for ($pass = 1; $pass <= $this->rectorConfig->maxPasses; ++$pass) {
            $improved = false;
            foreach ($rules as $rule) {
                ($this->log)("Pass {$pass}: {$rule->shortName()}");
                $step = $this->step($rule, $pass);
                $step->accepted === [] && $step->rejected === [] && $step->error === null or $report->steps[] = $step;
                $improved = $improved || $step->accepted !== [];
            }

            if (!$improved) {
                break;
            }
        }

        \array_push($report->notes, ...\array_keys($this->verifierNotes));
        $this->finalTests($report);
        $final = $this->countCurrent();
        $this->writeJson('99-final.json', $final);
        $report->opsAfter = self::total($this->counts);

        return $report;
    }

    /**
     * @return array<non-empty-string, array<non-empty-string, FunctionCount>>
     */
    private static function byFile(CountResult $result): array
    {
        $byFile = [];
        foreach ($result->functions as $function) {
            $byFile[$function->file][$function->key] = $function;
        }

        return $byFile;
    }

    /**
     * @param array<non-empty-string, array<non-empty-string, FunctionCount>> $counts
     */
    private static function total(array $counts): int
    {
        $total = 0;
        foreach ($counts as $functions) {
            foreach ($functions as $function) {
                $function->optimizable and $total += $function->opsOpt;
            }
        }

        return $total;
    }

    private function step(RuleSpec $rule, int $pass): StepReport
    {
        $step = new StepReport($rule->class, $pass);
        try {
            $errors = $this->rector->run($rule, \array_values($this->paths));
        } catch (\RuntimeException $e) {
            $this->restoreAll();
            $step->error = $e->getMessage();

            return $step;
        }

        $new = $this->takeChanges();
        foreach ($errors as $error) {
            $step->reject('-', '', 'Rector: ' . $error);
        }

        if ($new === []) {
            return $step;
        }

        $new = $this->format($new);
        $new = $this->syntax($new, $step);
        $countsNew = $this->countContents($new);

        $plans = [];
        foreach ($new as $relative => $content) {
            $plans[$relative] = $this->plan($relative, $content, $countsNew[$relative] ?? null, $rule, $step);
        }

        $accepted = $this->verify($plans, $step);
        if ($accepted === []) {
            return $step;
        }

        foreach ($accepted as $relative => $plan) {
            $step->before[$relative] = $this->current[$relative];
            $this->workspace->write($this->paths[$relative], $plan->candidate);
            $this->current[$relative] = $plan->candidate;
        }

        $countsAfter = $this->countContents([]);
        foreach ($accepted as $relative => $plan) {
            foreach ($plan->accepted as $top => $gain) {
                $step->accepted[] = ['file' => $relative, 'function' => $top, 'gain' => $gain, 'status' => $plan->statuses[$top] ?? 'verified'];
            }

            $this->counts[$relative] = $countsAfter[$relative] ?? $this->counts[$relative];
        }

        try {
            $step->commit = $this->workspace->commit(
                \array_map(fn(string $r): Path => $this->paths[$r], \array_keys($accepted)),
                $this->message($rule, $step),
            );
        } catch (\RuntimeException $e) {
            $step->error = $e->getMessage();
        }

        return $step;
    }

    /**
     * Decides which changed functions of one file are kept: the candidate content and why the others
     * are not.
     *
     * @param array<non-empty-string, FunctionCount>|null $countsNew
     * @param non-empty-string $relative
     */
    private function plan(string $relative, string $content, ?array $countsNew, RuleSpec $rule, StepReport $step): FilePlan
    {
        $current = $this->current[$relative];
        $before = Units::of($current, $relative);
        $after = Units::of($content, $relative);
        $plan = new FilePlan($current, $before, $after);
        if ($countsNew === null) {
            $step->reject($relative, '', 'the changed file cannot be counted');
            return $plan;
        }

        $tops = $before->changedTopLevel($after);
        $both = \array_values(\array_filter($tops, static fn(string $t): bool => isset($before->units[$t], $after->units[$t])));
        $plan->atomic = $both !== $tops || $before->withUnitsFrom($after, $both) !== $content;

        $reasons = [];
        $gains = [];
        foreach ($tops as $top) {
            [$gains[$top], $reasons[$top]] = $this->judge($relative, $top, $before, $after, $countsNew, $rule);
        }

        if ($plan->atomic) {
            $hard = \array_filter($reasons, static fn(?string $r): bool => $r !== null && !\str_starts_with($r, 'gain ') && !\str_starts_with($r, 'opcodes grew'));
            $total = \array_sum($gains);
            $grown = \array_filter($gains, static fn(int $g): bool => $g < 0);
            $threshold = $this->readability->reject($total, (new LineDiff($current, $content))->changedLines(), null, null, $rule->executedGain);
            if ($hard !== [] || $grown !== [] || $threshold !== null || $tops === []) {
                $why = $hard !== [] ? \reset($hard) : ($grown !== [] ? 'opcodes grew in ' . \implode(', ', \array_keys($grown)) : ($threshold ?? 'changes outside functions only'));
                $step->reject($relative, \implode(', ', $tops), "the file changes outside functions, so it is all or nothing: {$why}");

                return $plan;
            }

            $plan->candidate = $content;
            $plan->accepted = $gains;

            return $this->lineSensitive($relative, $plan, $step);
        }

        foreach ($tops as $top) {
            if ($reasons[$top] !== null) {
                $step->reject($relative, $top, $reasons[$top]);
                continue;
            }

            $plan->accepted[$top] = $gains[$top];
        }

        $plan->candidate = $plan->accepted === [] ? $current : (string) $before->withUnitsFrom($after, \array_keys($plan->accepted));

        return $this->lineSensitive($relative, $plan, $step);
    }

    /**
     * Gain of one top-level function with its closures, and why it is not kept (null — kept).
     *
     * @param array<non-empty-string, FunctionCount> $countsNew
     * @param non-empty-string $relative
     * @param non-empty-string $top
     * @return array{int, ?string}
     */
    private function judge(string $relative, string $top, Units $before, Units $after, array $countsNew, RuleSpec $rule): array
    {
        $family = $before->family($top);
        $newFamily = $after->family($top);
        $sorted = $family;
        $sortedNew = $newFamily;
        \sort($sorted);
        \sort($sortedNew);
        if ($sorted !== $sortedNew) {
            return [0, 'adds or removes a function'];
        }

        $gain = 0;
        $restrictions = [];
        foreach ($family as $key) {
            $old = $this->counts[$relative][$key] ?? null;
            $new = $countsNew[$key] ?? null;
            if ($old === null || $new === null) {
                return [0, "{$key} is not counted"];
            }

            $gain += $old->opsOpt - $new->opsOpt;
            $restrictions[$key] = Flag::restrictionsOf(Flag::fromValues($old->flags));
        }

        $unit = $before->units[$top];
        $names = \array_values(\array_filter([$rule->alias(), \strtolower($rule->shortName())]));
        if ($this->ignoredByConfig($top) || ($unit->node !== null && IgnoreMarks::ignored($unit->node, $names))
            || ($unit->class !== null && IgnoreMarks::ignored($unit->class, $names))
        ) {
            return [$gain, 'excluded by the user (ignore)'];
        }

        foreach ($restrictions as $key => $list) {
            if (\in_array(Restriction::Skip, $list, true)) {
                return [$gain, "{$key} uses eval or include: never changed"];
            }

            if (\in_array(Restriction::LineSensitive, $list, true)) {
                return [$gain, "{$key} depends on line numbers: never changed"];
            }
        }

        $changedLines = (new LineDiff(
            ($before->docComment($top) ?? '') . ($before->source($top) ?? ''),
            ($after->docComment($top) ?? '') . ($after->source($top) ?? ''),
        ))->changedLines();
        $why = $this->readability->reject($gain, $changedLines, $unit->node, $after->units[$top]->node, $rule->executedGain);
        if ($why !== null) {
            return [$gain, $why];
        }

        foreach ($family as $key) {
            $old = $before->units[$key]->node;
            $new = $after->units[$key]->node;
            if ($old === null || $new === null) {
                continue;
            }

            $why = ($key === $top ? null : $this->readability->compare($old, $new))
                ?? $this->signatures->reject($old, $new, $before->docComment($key), $after->docComment($key), $before->units[$key]->class, $restrictions[$key]);
            if ($why !== null) {
                return [$gain, $key === $top ? $why : "{$key}: {$why}"];
            }
        }

        return [$gain, null];
    }

    /**
     * A change that moves a line-sensitive function (`__LINE__`, backtraces) of the file changes its
     * behavior: such a file keeps its current version.
     *
     * @param non-empty-string $relative
     */
    private function lineSensitive(string $relative, FilePlan $plan, StepReport $step): FilePlan
    {
        if ($plan->candidate === $plan->current) {
            return $plan;
        }

        $moved = Units::of($plan->candidate, $relative);
        foreach ($plan->before->units as $key => $unit) {
            $flags = Flag::fromValues(isset($this->counts[$relative][$key]) ? $this->counts[$relative][$key]->flags : []);
            if (\in_array(Restriction::LineSensitive, Flag::restrictionsOf($flags), true)
                && ($moved->units[$key] ?? null)?->line !== $unit->line
            ) {
                $step->reject($relative, \implode(', ', \array_keys($plan->accepted)), "the change moves {$key}, which depends on line numbers");
                $plan->candidate = $plan->current;
                $plan->accepted = [];

                return $plan;
            }
        }

        return $plan;
    }

    /**
     * Verifies the candidates; a rejected function is taken back (the whole file when it is
     * all-or-nothing) and the rest is verified again.
     *
     * @param array<non-empty-string, FilePlan> $plans
     * @return array<non-empty-string, FilePlan> The files to write.
     */
    private function verify(array $plans, StepReport $step): array
    {
        $runDir = $this->workspace->runDir;
        for ($round = 0; $round < 50; ++$round) {
            $pending = \array_filter($plans, static fn(FilePlan $p): bool => $p->candidate !== $p->current && $p->accepted !== []);
            if ($pending === []) {
                return [];
            }

            $changes = [];
            foreach ($pending as $relative => $plan) {
                $changes[] = [$this->paths[$relative], $plan->candidate];
            }

            ($this->log)('Verifying ' . \count($pending) . ' file(s)');
            $report = $this->verifier->verifyAll($changes, null, true, $runDir->join('counterexamples'));
            foreach ($report->notes as $note) {
                # Per-function notes (`X is new`) belong to the step, not to the run.
                \str_starts_with($note, '`') or $this->verifierNotes[$note] = true;
            }
            if ($report->accepted()) {
                foreach ($report->functions as $function) {
                    foreach ($pending as $relative => $plan) {
                        $top = Units::of($plan->candidate, $relative)->topLevel($function->key) ?? $function->key;
                        isset($plan->accepted[$top]) and $plan->statuses[$top] = $function->status;
                    }
                }

                return $pending;
            }

            if (!$this->takeBack($pending, $report, $step)) {
                return [];
            }
        }

        return [];
    }

    /**
     * Takes back what the verification rejected. False when nothing can be attributed: the step is dropped.
     *
     * @param array<non-empty-string, FilePlan> $pending
     */
    private function takeBack(array $pending, CandidateReport $report, StepReport $step): bool
    {
        $tests = $report->tests;
        $reason = match (true) {
            $report->syntaxError !== null => 'syntax error: ' . $report->syntaxError,
            $tests !== null && !$tests->success => 'project tests fail: ' . \implode(', ', \array_slice($tests->failed, 0, 5)),
            default => null,
        };
        if ($reason !== null) {
            foreach ($pending as $relative => $plan) {
                $step->reject($relative, \implode(', ', \array_keys($plan->accepted)), $reason);
                $plan->candidate = $plan->current;
                $plan->accepted = [];
            }

            return false;
        }

        $taken = false;
        foreach ($pending as $relative => $plan) {
            $units = Units::of($plan->candidate, $relative);
            $reject = [];
            foreach ($report->functions as $function) {
                if (!$function->accepted()) {
                    $top = $units->topLevel($function->key) ?? $function->key;
                    isset($plan->accepted[$top]) and $reject[$top] = $function->reason;
                }
            }

            foreach ($report->phpstan as $error) {
                if (\realpath($error['file']) !== \realpath((string) $this->paths[$relative])) {
                    continue;
                }

                $top = $this->unitAtLine($units, $error['line']);
                $reject[$top ?? '*'] = 'new PHPStan error: ' . $error['message'];
            }

            if ($reject === []) {
                continue;
            }

            $taken = true;
            if ($plan->atomic || isset($reject['*'])) {
                $step->reject($relative, \implode(', ', \array_keys($plan->accepted)), \reset($reject));
                $plan->candidate = $plan->current;
                $plan->accepted = [];
                continue;
            }

            foreach ($reject as $top => $reason) {
                $step->reject($relative, $top, $reason);
                unset($plan->accepted[$top]);
            }

            $plan->candidate = $plan->accepted === [] ? $plan->current : (string) $plan->before->withUnitsFrom($plan->after, \array_keys($plan->accepted));
        }

        return $taken;
    }

    private function unitAtLine(Units $units, int $line): ?string
    {
        foreach ($units->units as $key => $unit) {
            if ($line >= $unit->line && $line <= $unit->endLine) {
                return $units->topLevel($key);
            }
        }

        return null;
    }

    /**
     * The files Rector changed, read and restored to their current version.
     *
     * @return array<non-empty-string, string>
     */
    private function takeChanges(): array
    {
        $new = [];
        foreach ($this->paths as $relative => $path) {
            $content = (string) @\file_get_contents((string) $path);
            if ($content !== $this->current[$relative]) {
                $this->workspace->backup($path, $this->current[$relative]);
                $new[$relative] = $content;
                \file_put_contents((string) $path, $this->current[$relative]);
            }
        }

        return $new;
    }

    /**
     * @param array<non-empty-string, string> $new
     * @return array<non-empty-string, string>
     */
    private function format(array $new): array
    {
        $formattable = \array_diff_key($new, $this->unformatted);
        if (!$this->formatter->enabled() || $formattable === []) {
            return $new;
        }

        try {
            $formatted = $this->withContents($formattable, function () use ($formattable): array {
                $this->formatter->format(\array_map(fn(string $r): Path => $this->paths[$r], \array_keys($formattable)));
                $result = [];
                foreach (\array_keys($formattable) as $relative) {
                    $result[$relative] = (string) \file_get_contents((string) $this->paths[$relative]);
                }

                return $result;
            });
        } catch (\RuntimeException $e) {
            ($this->log)($e->getMessage());

            return $new;
        }

        return \array_replace($new, $formatted);
    }

    /**
     * @param array<non-empty-string, string> $new
     * @return array<non-empty-string, string>
     */
    private function syntax(array $new, StepReport $step): array
    {
        $checker = new SyntaxChecker($this->php, $this->workDir);
        foreach ($new as $relative => $content) {
            $error = $checker->check($content);
            if ($error !== null) {
                $step->reject($relative, '', 'syntax error after the rule: ' . $error);
                unset($new[$relative]);
            }
        }

        return $new;
    }

    /**
     * Counts of the given contents (the current one for the rest), by file and key.
     *
     * @param array<non-empty-string, string> $contents
     * @return array<non-empty-string, array<non-empty-string, FunctionCount>>
     */
    private function countContents(array $contents): array
    {
        return $this->withContents($contents, function () use ($contents): array {
            $files = $contents === [] ? \array_values($this->paths) : \array_map(fn(string $r): Path => $this->paths[$r], \array_keys($contents));

            return self::byFile($this->counted($files));
        });
    }

    private function countCurrent(): CountResult
    {
        $result = $this->counted(\array_values($this->paths));
        $this->counts = self::byFile($result);

        return $result;
    }

    /**
     * @param list<Path> $files
     */
    private function counted(array $files): CountResult
    {
        $result = $this->counter->count($this->project, $files);
        if ($this->references === null) {
            return $result;
        }

        return new CountResult($this->references->apply($result->functions), $result->errors, $result->files, $result->cached);
    }

    /**
     * Runs `$body` with the given contents on disk, then restores the current ones.
     *
     * @template T
     * @param array<non-empty-string, string> $contents
     * @param \Closure(): T $body
     * @return T
     */
    private function withContents(array $contents, \Closure $body): mixed
    {
        try {
            foreach ($contents as $relative => $content) {
                \file_put_contents((string) $this->paths[$relative], $content);
            }

            return $body();
        } finally {
            foreach (\array_keys($contents) as $relative) {
                \file_put_contents((string) $this->paths[$relative], $this->current[$relative]);
            }
        }
    }

    private function restoreAll(): void
    {
        foreach ($this->paths as $relative => $path) {
            (string) @\file_get_contents((string) $path) === $this->current[$relative] or \file_put_contents((string) $path, $this->current[$relative]);
        }
    }

    /**
     * Files the formatter would change before any step are not formatted at all: their whole diff
     * would be the formatter's.
     */
    private function detectUnformatted(RunReport $report): void
    {
        $report->notes[] = 'Formatter: ' . $this->formatter->describe();
        if (!$this->formatter->enabled()) {
            return;
        }

        try {
            $this->formatter->format(\array_values($this->paths));
        } catch (\RuntimeException $e) {
            $report->notes[] = $e->getMessage();
        }

        foreach ($this->paths as $relative => $path) {
            if ((string) \file_get_contents((string) $path) !== $this->current[$relative]) {
                $this->unformatted[$relative] = true;
                \file_put_contents((string) $path, $this->current[$relative]);
            }
        }

        $this->unformatted === [] or $report->notes[] = \count($this->unformatted)
            . ' file(s) do not follow the formatter already and are not formatted: ' . \implode(', ', \array_slice(\array_keys($this->unformatted), 0, 10));
    }

    /**
     * The full run of the project's tests at the end (steps run only the tests of changed functions).
     * When it fails, the accepted steps are taken back from the last one until it passes.
     */
    private function finalTests(RunReport $report): void
    {
        if ($report->steps === [] || \array_filter($report->steps, static fn(StepReport $s): bool => $s->accepted !== []) === []) {
            return;
        }

        ($this->log)('Final run of the project\'s tests');
        $result = $this->verifier->runAllTests();
        if ($result === null) {
            return;
        }

        $report->finalTests = $result->success;
        if ($result->success) {
            return;
        }

        $report->notes[] = 'The full test run fails after the optimization: ' . \implode(', ', \array_slice($result->failed, 0, 10))
            . '. The steps are taken back from the last one until it passes; check the selection of tests by coverage.';
        foreach (\array_reverse($report->steps) as $step) {
            if ($step->accepted === []) {
                continue;
            }

            # The later steps are taken back already: the files are as this step left them.
            $this->revertStep($step);
            $result = $this->verifier->runAllTests();
            if ($result !== null && $result->success) {
                $report->finalTests = true;

                return;
            }
        }
    }

    private function revertStep(StepReport $step): void
    {
        $paths = [];
        foreach ($step->before as $relative => $content) {
            $this->workspace->write($this->paths[$relative], $content);
            $this->current[$relative] = $content;
            $paths[] = $this->paths[$relative];
        }

        foreach ($step->accepted as $change) {
            $step->reject($change['file'], $change['function'], 'taken back: the full test run fails');
        }

        $step->accepted = [];
        $this->workspace->commit($paths, "opmin: take back {$step->rule}\n\nThe full run of the project's tests fails with it.");
    }

    private function message(RuleSpec $rule, StepReport $step): string
    {
        $lines = [\sprintf('opmin: %s, -%d opcodes in %d function(s)', $rule->shortName(), $step->gain(), \count($step->accepted)), '', 'Rule: ' . $rule->class];
        foreach ($step->accepted as $change) {
            $lines[] = \sprintf('%s  -%d (%s)', $change['function'], $change['gain'], $change['status']);
        }

        return \implode("\n", $lines);
    }

    private function ignoredByConfig(string $key): bool
    {
        foreach ($this->ignore->functions as $pattern) {
            $regex = '~^' . \str_replace('\*', '.*', \preg_quote(\ltrim(\str_replace('\\\\', '\\', $pattern), '\\'), '~')) . '$~i';
            if (\preg_match($regex, $key) === 1) {
                return true;
            }
        }

        return false;
    }

    private function writeJson(string $name, CountResult $result): void
    {
        $functions = [];
        foreach ($result->functions as $function) {
            $functions[$function->key] = $function->toArray();
        }

        \ksort($functions, \SORT_STRING);
        \file_put_contents(
            (string) $this->workspace->runDir->join($name),
            \json_encode(['functions' => $functions, 'errors' => $result->errors], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n",
        );
    }
}
