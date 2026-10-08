<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;
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
use Opmin\Module\Optimize\Review\Change;
use Opmin\Module\Optimize\Review\Decision;
use Opmin\Module\Optimize\Review\Declined;
use Opmin\Module\Optimize\Review\Reviewer;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Project\Project;
use Opmin\Module\Report\RejectionKind;
use Opmin\Module\Tests\TestResult;
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

    /** @var array<string, string> Relative file => why its last counted content could not be counted. */
    private array $countErrors = [];

    /** @var array<string, true> What the verifier could not check, once per run. */
    private array $verifierNotes = [];

    /** @var array<string, string> "rule\0file\0function" => hash of the function when the rule's change of it was rolled back. */
    private array $rolledBack = [];

    private readonly Readability $readability;
    private readonly SignatureGate $signatures;
    private readonly Declined $declined;
    private ?Reviewer $reviewer = null;

    /** `opmin.baseline.yaml` when the review of this run added to it. */
    private ?Path $declinedFile = null;

    /** @var array<class-string, true> Rules the review answered "all" for. */
    private array $approvedRules = [];

    /** The run ends after the current step (`q` in the review, a signal). */
    private bool $stopped = false;

    /** A signal reached the tools of the run: what they answer now is not trusted. */
    private bool $interrupted = false;

    private ?RunState $state = null;

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
        ?Declined $declined = null,
    ) {
        $this->declined = $declined ?? Declined::none();
        $this->readability = new Readability($readability);
        $this->signatures = new SignatureGate($signatures, $allowPublicSignatures);
        $this->log = $log ?? static function (string $message): void {};
    }

    /**
     * @param list<Path> $files Absolute target files.
     * @param list<RuleSpec> $rules In the order of application.
     * @param RunState|null $state Saved after every step; a state with steps or a position continues
     *        that run (`--resume`): the files on disk must be its accepted ones ({@see RunState::restore()}).
     */
    public function run(array $files, array $rules, ?RunState $state = null): RunReport
    {
        $resumed = $state !== null && ($state->steps !== [] || $state->pass > 1 || $state->rule > 0 || $state->phase !== 'steps');
        $report = $this->open($files, $resumed ? null : '00-baseline.json', detect: !$resumed);
        $this->state = $state;
        if ($state !== null && $resumed) {
            $report->opsBefore = $state->opsBefore;
            $report->steps = $state->steps;
            $report->notes = $state->notes;
            $this->remember($state->memory);
        } elseif ($state !== null) {
            $state->opsBefore = $report->opsBefore;
            $state->notes = $report->notes;
            $this->checkpoint($report, 1, 0, false);
        }

        $pass = $state?->pass ?? 1;
        $next = $state?->rule ?? 0;
        $improved = $state?->improved ?? false;
        for (; ($state?->phase ?? 'steps') === 'steps' && $pass <= $this->rectorConfig->maxPasses && !$this->stopped(); ++$pass) {
            for ($i = $next; $i < \count($rules) && !$this->stopped(); ++$i) {
                $rule = $rules[$i];
                ($this->log)("Pass {$pass}: {$rule->shortName()}");
                $step = $this->step($rule, $pass);
                if ($this->signalled() && $step->accepted === []) {
                    # A signal killed the tools of the step: its verdicts are not real; the step runs again on resume.
                    break 2;
                }

                $step->accepted === [] && $step->rejected === [] && $step->error === null or $report->steps[] = $step;
                $improved = $improved || $step->accepted !== [];
                $this->checkpoint($report, $pass, $i + 1, $improved, $step);
            }

            $next = 0;
            if ($this->stopped() || !$improved) {
                break;
            }

            $improved = false;
            $this->checkpoint($report, $pass + 1, 0, false);
        }

        if ($this->stopped()) {
            $report->interrupted = true;
            $this->close($report, finalTests: false);

            return $report;
        }

        if ($state !== null) {
            $state->phase = 'closing';
            $state->save();
        }

        $this->close($report);
        $report->interrupted = $this->signalled();
        if ($state !== null && !$this->signalled()) {
            $state->phase = 'finished';
            $this->checkpoint($report, $state->pass, $state->rule, $state->improved);
        }

        return $report;
    }

    /**
     * Ends the run as soon as possible: the tools the signal reached are not trusted, the current
     * step is dropped unless it was verified already (`--resume` runs it again).
     */
    public function interrupt(): void
    {
        $this->interrupted = true;
        $this->stopped = true;
    }

    /**
     * What the optimizer learned in a run and a resumed run must know.
     *
     * @return array<string, mixed>
     */
    public function memory(): array
    {
        return [
            'rolled_back' => $this->rolledBack,
            'approved_rules' => \array_keys($this->approvedRules),
            'verifier_notes' => \array_keys($this->verifierNotes),
            'unformatted' => \array_keys($this->unformatted),
        ];
    }

    /**
     * Asks the reviewer about every change that passed the checks (`--review`).
     */
    public function withReviewer(Reviewer $reviewer): void
    {
        $this->reviewer = $reviewer;
    }

    /**
     * Ends the run after the current step.
     */
    public function stop(): void
    {
        $this->stopped = true;
    }

    public function stopped(): bool
    {
        return $this->stopped;
    }

    /**
     * Whether a signal reached the run (its tools may have died with it).
     */
    public function signalled(): bool
    {
        return $this->interrupted;
    }

    /**
     * Starts a run on the given files: reads and counts them, finds the files the formatter must not touch.
     *
     * @param list<Path> $files Absolute target files.
     * @param non-empty-string|null $baseline Name of the file in the run directory for the counts; null — not written.
     * @param 'optimize'|'llm' $kind
     * @param bool $detect Find the files the formatter must not touch (a resumed run knows them).
     */
    public function open(array $files, ?string $baseline = '00-baseline.json', string $kind = 'optimize', bool $detect = true): RunReport
    {
        foreach ($files as $file) {
            $relative = $this->project->relative($file);
            $this->paths[$relative] = $file;
            $this->current[$relative] = (string) \file_get_contents((string) $file);
            # Every original is kept, also under git: a crash may leave any target file half-way.
            $this->workspace->backup($file);
        }

        $counts = $this->countCurrent();
        $report = new RunReport(self::total($this->counts), (string) $this->workspace->runDir, $kind);
        $baseline === null or $this->writeJson($baseline, $counts);
        foreach ($counts->errors as $file => $error) {
            $report->notes[] = "{$file} cannot be counted and is not changed: {$error}";
        }

        $detect and $this->detectUnformatted($report);

        return $report;
    }

    /**
     * Ends a run: the full test run of the project (taking back steps while it fails), the final counts.
     *
     * @param bool $finalTests False for an interrupted run: the full test run is left for `--resume`.
     */
    public function close(RunReport $report, bool $finalTests = true): void
    {
        \array_push($report->notes, ...\array_keys($this->verifierNotes));
        if ($this->declinedFile !== null) {
            try {
                $this->workspace->commit([$this->declinedFile], "opmin: remember the changes declined in the review\n\nThey are listed in " . Declined::FILE . '.');
            } catch (\RuntimeException $e) {
                $report->notes[] = Declined::FILE . ' is not committed: ' . $e->getMessage();
            }
        }

        $finalTests
            ? $this->finalTests($report)
            : $report->notes[] = 'The run was interrupted before the full run of the project\'s tests: `opmin optimize --resume` continues it.';
        $final = $this->countCurrent();
        $this->writeJson('99-final.json', $final);
        $report->opsAfter = self::total($this->counts);
        foreach ($report->steps as $step) {
            foreach ($step->accepted as $change) {
                $report->functions[$change['function']] ??= $this->familyNow($change['file'], $change['function']);
            }
        }
    }

    /**
     * The project's tests on the original code when they are red: nothing can be verified with them.
     */
    public function failingTestsOnOriginal(): ?TestResult
    {
        return $this->verifier->failingTestsOnOriginal();
    }

    /**
     * What the report needs from the tools of the run: the formatter and the test runner.
     *
     * @return array{formatter: string, test_runner: ?string}
     */
    public function tools(): array
    {
        return ['formatter' => $this->formatter->describe(), 'test_runner' => $this->verifier->runnerName()];
    }

    /**
     * Stage B: one rewritten top-level function proposed by the LLM, through the same checks as a
     * Rector step. Nothing but the function may change: its source (with its docblock, when the
     * candidate has one) is replaced in the current file.
     *
     * @param non-empty-string $relative File of the function, relative to the project root, opened with {@see self::open()}.
     * @param non-empty-string $top Key of the top-level function.
     */
    public function candidate(string $relative, string $top, string $source): StepReport
    {
        $rule = new RuleSpec(LlmCandidate::class);
        $step = new StepReport($rule->class, 1);
        $current = $this->current[$relative] ?? null;
        $units = $current === null ? null : Units::of($current, $relative);
        $content = $units?->withSource($top, $source);
        if ($units === null || $content === null) {
            $step->reject($relative, $top, "no function {$top} in {$relative}");
            return $step;
        }

        if (\trim($source) === '' || $content === $current) {
            $step->reject($relative, $top, 'the candidate does not change the function');
            return $step;
        }

        $changed = $units->changedTopLevel(Units::of($content, $relative));
        if ($changed !== [$top]) {
            $others = \array_values(\array_diff($changed, [$top]));
            $step->reject($relative, $top, $others === []
                ? 'the candidate cannot be parsed as the function ' . $top
                : 'the candidate changes more than ' . $top . ': ' . \implode(', ', $others));

            return $step;
        }

        $this->apply([$relative => $content], $rule, $step);

        return $step;
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

    private static function fingerprint(Units $units, string $top): string
    {
        return \sha1(($units->docComment($top) ?? '') . "\0" . ($units->source($top) ?? ''));
    }

    /**
     * @param array<array-key, mixed> $memory {@see self::memory()}
     */
    private function remember(array $memory): void
    {
        /** @var array{rolled_back?: array<string, string>, approved_rules?: list<class-string>, verifier_notes?: list<string>, unformatted?: list<non-empty-string>} $memory */
        $this->rolledBack = $memory['rolled_back'] ?? [];
        $this->approvedRules = \array_fill_keys($memory['approved_rules'] ?? [], true);
        $this->verifierNotes = \array_fill_keys($memory['verifier_notes'] ?? [], true);
        $this->unformatted = \array_fill_keys($memory['unformatted'] ?? [], true);
    }

    /**
     * Saves the position after a step: the files it changed, the steps, the memory, HEAD.
     */
    private function checkpoint(RunReport $report, int $pass, int $rule, bool $improved, ?StepReport $step = null): void
    {
        $state = $this->state;
        if ($state === null) {
            return;
        }

        foreach (\array_keys($step?->before ?? []) as $relative) {
            $state->keep($relative, $this->current[$relative]);
        }

        $state->pass = $pass;
        $state->rule = $rule;
        $state->improved = $improved;
        $state->steps = $report->steps;
        $state->memory = $this->memory();
        $state->head = $this->workspace->head();
        $state->save();
    }

    /**
     * Opcodes and flags of a top-level function with its closures as it is now.
     *
     * @param non-empty-string $relative
     * @param non-empty-string $top
     * @return array{file: non-empty-string, ops_after: int, flags: list<string>}
     */
    private function familyNow(string $relative, string $top): array
    {
        $ops = 0;
        $flags = [];
        $units = Units::of($this->current[$relative] ?? '', $relative);
        foreach ($units->family($top) as $key) {
            $count = $this->counts[$relative][$key] ?? null;
            if ($count !== null) {
                $ops += $count->opsOpt;
                $flags = [...$flags, ...$count->flags];
            }
        }

        return ['file' => $relative, 'ops_after' => $ops, 'flags' => \array_values(\array_unique($flags))];
    }

    private function step(RuleSpec $rule, int $pass): StepReport
    {
        $step = new StepReport($rule->class, $pass);
        $step->executedGain = $rule->executedGain;
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

        $new === [] or $this->apply($new, $rule, $step);
        $this->signalled() or $this->rememberDeclined($rule, $step);

        return $step;
    }

    /**
     * Remembers the functions whose change by the rule was rolled back for a reason of their own (not
     * the project's tests or a whole file), as they are now — rolled back, so as before the step. A
     * later pass that gets the same change of the same code skips it.
     */
    private function rememberDeclined(RuleSpec $rule, StepReport $step): void
    {
        foreach ($step->rejected as $rejected) {
            $relative = $rejected['file'];
            $top = $rejected['function'];
            $kind = RejectionKind::fromReason($rejected['reason']);
            if ($top === '' || \str_contains($top, ', ') || !isset($this->current[$relative])
                || \in_array($kind, [RejectionKind::Tests, RejectionKind::Other], true) || \str_contains($rejected['reason'], 'all or nothing')
            ) {
                continue;
            }

            $units = Units::of($this->current[$relative], $relative);
            isset($units->units[$top]) and $this->rolledBack["{$rule->class}\0{$relative}\0{$top}"] = self::fingerprint($units, $top);
        }
    }

    /**
     * @param non-empty-string $relative
     * @param non-empty-string $top
     */
    private function declinedBefore(RuleSpec $rule, string $relative, string $top, Units $before): bool
    {
        $known = $this->rolledBack["{$rule->class}\0{$relative}\0{$top}"] ?? null;

        return $known !== null && isset($before->units[$top]) && $known === self::fingerprint($before, $top);
    }

    /**
     * The common part of a step: format → `php -l` → count → keep what is worth it → verify → write and commit.
     *
     * @param non-empty-array<non-empty-string, string> $new Changed files => their new content.
     */
    private function apply(array $new, RuleSpec $rule, StepReport $step): void
    {
        $new = $this->format($new);
        $new = $this->syntax($new, $step);
        $countsNew = $this->countContents($new);

        $plans = [];
        foreach ($new as $relative => $content) {
            $plans[$relative] = $this->plan($relative, $content, $countsNew[$relative] ?? null, $rule, $step);
        }

        $accepted = $this->verify($plans, $step);
        $this->reviewer === null || $accepted === [] or $accepted = $this->review($accepted, $rule, $step);
        if ($accepted === []) {
            return;
        }

        foreach ($accepted as $relative => $plan) {
            $step->before[$relative] = $this->current[$relative];
            $this->workspace->write($this->paths[$relative], $plan->candidate);
            $this->current[$relative] = $plan->candidate;
        }

        $countsAfter = $this->countContents([]);
        foreach ($accepted as $relative => $plan) {
            foreach ($plan->accepted as $top => $gain) {
                $step->accepted[] = [
                    'file' => $relative,
                    'function' => $top,
                    'gain' => $gain,
                    'status' => $plan->statuses[$top] ?? 'verified',
                    'checks' => $plan->checks[$top] ?? [],
                ];
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
            $step->reject($relative, '', 'the changed file cannot be counted: ' . ($this->countErrors[$relative] ?? 'no functions'));
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
            if ($this->declinedBefore($rule, $relative, $top, $before)) {
                # The same change of the same code was rolled back in an earlier pass: not judged again.
                continue;
            }

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
        if (IgnoreMarks::byConfig($this->ignore->functions, $top) || ($unit->node !== null && IgnoreMarks::ignored($unit->node, $names))
            || ($unit->class !== null && IgnoreMarks::ignored($unit->class, $names))
        ) {
            return [$gain, 'excluded by the user (ignore)'];
        }

        if ($this->declined->has($top, [...$names, $rule->class])) {
            return [$gain, 'declined in the review earlier (' . Declined::FILE . ')'];
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
                        if (isset($plan->accepted[$top])) {
                            # The weakest proof of the family is the status of the change.
                            $plan->statuses[$top] = RunReport::weaker($plan->statuses[$top] ?? null, $function->status);
                            $plan->checks[$top][] = StepReport::check($function);
                        }
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
            $proofs = [];
            foreach ($report->functions as $function) {
                if (!$function->accepted()) {
                    $top = $units->topLevel($function->key) ?? $function->key;
                    if (isset($plan->accepted[$top])) {
                        $reject[$top] = $function->reason;
                        $counterexample = $function->verdict?->counterexample;
                        $counterexample === null or $proofs[$top] = ['test' => $function->counterexampleTest] + $counterexample->toArray();
                    }
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
                $step->reject($relative, \implode(', ', \array_keys($plan->accepted)), \reset($reject), \reset($proofs) ?: null);
                $plan->candidate = $plan->current;
                $plan->accepted = [];
                continue;
            }

            foreach ($reject as $top => $reason) {
                $step->reject($relative, $top, $reason, $proofs[$top] ?? null);
                unset($plan->accepted[$top]);
            }

            $plan->candidate = $plan->accepted === [] ? $plan->current : (string) $plan->before->withUnitsFrom($plan->after, \array_keys($plan->accepted));
        }

        return $taken;
    }

    /**
     * Asks the reviewer about every verified change: a function (a whole file changed outside
     * functions) at a time. What is declined is rolled back and remembered in `opmin.baseline.yaml`;
     * when only a part of the step is left, it is verified again — the checks ran on all of it.
     *
     * @param array<non-empty-string, FilePlan> $plans
     * @return array<non-empty-string, FilePlan>
     */
    private function review(array $plans, RuleSpec $rule, StepReport $step): array
    {
        \assert($this->reviewer !== null);
        $alias = $rule->alias();
        $name = $alias === null || $alias === '' ? $rule->shortName() : $alias;
        $partial = false;
        foreach ($plans as $relative => $plan) {
            $groups = $plan->atomic ? [\array_keys($plan->accepted)] : \array_map(static fn(string $t): array => [$t], \array_keys($plan->accepted));
            foreach ($groups as $tops) {
                if ($tops === [] || isset($this->approvedRules[$rule->class])) {
                    continue;
                }

                $decision = $this->stopped ? Decision::Quit : $this->reviewer->review($this->change($relative, $plan, $tops, $rule, $name, $step));
                if ($decision === Decision::All) {
                    $this->approvedRules[$rule->class] = true;
                }

                if ($decision === Decision::Yes || $decision === Decision::All) {
                    continue;
                }

                $decision === Decision::Quit and $this->stopped = true;
                foreach ($tops as $top) {
                    $decision === Decision::No and $this->declined->add($top, $name);
                    $step->reject($relative, $top, $decision === Decision::No ? 'declined in the review' : 'declined in the review: the run was stopped');
                    unset($plan->accepted[$top], $plan->statuses[$top], $plan->checks[$top]);
                }

                $partial = true;
            }

            # A file changed outside functions is one answer: all or nothing.
            if ($plan->accepted === []) {
                $plan->candidate = $plan->current;
            } elseif (!$plan->atomic) {
                $plan->candidate = (string) $plan->before->withUnitsFrom($plan->after, \array_keys($plan->accepted));
            }
        }

        $this->declinedFile = $this->declined->save() ?? $this->declinedFile;
        $left = \array_filter($plans, static fn(FilePlan $p): bool => $p->accepted !== []);
        if (!$partial || $left === []) {
            return $left;
        }

        ($this->log)('Verifying what is left of the step after the review');

        return $this->verify($left, $step);
    }

    /**
     * @param non-empty-string $relative
     * @param non-empty-list<non-empty-string> $tops
     * @param non-empty-string $name
     */
    private function change(string $relative, FilePlan $plan, array $tops, RuleSpec $rule, string $name, StepReport $step): Change
    {
        if ($plan->atomic) {
            $diff = (new LineDiff($plan->current, $plan->candidate))->unified();
        } else {
            $top = $tops[0];
            $diff = (new LineDiff(
                ($plan->before->docComment($top) ?? '') . ($plan->before->source($top) ?? ''),
                ($plan->after->docComment($top) ?? '') . ($plan->after->source($top) ?? ''),
            ))->unified();
        }

        $status = null;
        $checks = [];
        $gain = 0;
        foreach ($tops as $top) {
            $gain += $plan->accepted[$top] ?? 0;
            $status = RunReport::weaker($status, $plan->statuses[$top] ?? 'verified');
            \array_push($checks, ...($plan->checks[$top] ?? []));
        }

        return new Change($relative, $tops, $rule->class, $name, $gain, $status, $checks, $diff, $step->executedGain);
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
                FS::replace((string) $path, $this->current[$relative]);
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
        $this->countErrors = $result->errors;
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
                FS::replace((string) $this->paths[$relative], $content);
            }

            return $body();
        } finally {
            foreach (\array_keys($contents) as $relative) {
                FS::replace((string) $this->paths[$relative], $this->current[$relative]);
            }
        }
    }

    private function restoreAll(): void
    {
        foreach ($this->paths as $relative => $path) {
            (string) @\file_get_contents((string) $path) === $this->current[$relative] or FS::replace((string) $path, $this->current[$relative]);
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
                FS::replace((string) $path, $this->current[$relative]);
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
        if ($result === null || $this->signalled()) {
            # A test run stopped by a signal says nothing: `--resume` runs it again.
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
            $this->checkpoint($report, $this->state?->pass ?? 1, $this->state?->rule ?? 0, $this->state?->improved ?? false, $step);
            $result = $this->verifier->runAllTests();
            /** @psalm-suppress TypeDoesNotContainType A signal can come during the test run. */
            if ($this->signalled()) {
                return;
            }

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
        $title = $rule->executedGain && $step->gain() === 0
            ? \sprintf('opmin: %s, fewer executed opcodes in %d function(s)', $rule->shortName(), \count($step->accepted))
            : \sprintf('opmin: %s, -%d opcodes in %d function(s)', $rule->shortName(), $step->gain(), \count($step->accepted));
        $rule->class === LlmCandidate::class and $title = \sprintf(
            'opmin: LLM rewrite of %s, -%d opcodes',
            \implode(', ', \array_column($step->accepted, 'function')),
            $step->gain(),
        );
        $lines = [$title, '', 'Rule: ' . $rule->class];
        foreach ($step->accepted as $change) {
            $lines[] = \sprintf('%s  -%d (%s)', $change['function'], $change['gain'], $change['status']);
        }

        return \implode("\n", $lines);
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
