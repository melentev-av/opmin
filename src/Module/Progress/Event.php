<?php

declare(strict_types=1);

namespace Opmin\Module\Progress;

/**
 * One event of the progress of a run. The renderers ({@see Renderer}) build their output from the fields,
 * never from a message: the line of {@see self::text()} is one of the renderings.
 *
 * @internal
 */
final readonly class Event
{
    /**
     * @param string|null $subject The rule, the function, the runner or the message of a warning.
     * @param int|null $done Functions whose differential test is done in the round.
     * @param int|null $total Functions of the round, files to count or verify, tests selected.
     * @param int|null $round 1 for the first verification of a step, more after a take-back.
     * @param int $jobs Differential tests at a time.
     */
    private function __construct(
        public EventKind $kind,
        public ?string $subject = null,
        public ?int $pass = null,
        public ?int $done = null,
        public ?int $total = null,
        public ?int $round = null,
        public int $kept = 0,
        public int $rolledBack = 0,
        public int $gain = 0,
        public ?string $error = null,
        public int $jobs = 1,
    ) {}

    public static function counting(int $files): self
    {
        return new self(EventKind::Counting, total: $files);
    }

    public static function originalTests(string $runner): self
    {
        return new self(EventKind::OriginalTests, subject: $runner);
    }

    public static function coverageMap(): self
    {
        return new self(EventKind::CoverageMap);
    }

    public static function pass(int $pass, string $rule): self
    {
        return new self(EventKind::Pass, subject: $rule, pass: $pass);
    }

    public static function rector(int $files): self
    {
        return new self(EventKind::Rector, total: $files);
    }

    public static function verifying(int $files, int $round): self
    {
        return new self(EventKind::Verifying, total: $files, round: $round);
    }

    public static function reviewLeft(): self
    {
        return new self(EventKind::ReviewLeft);
    }

    public static function phpStan(): self
    {
        return new self(EventKind::PhpStan);
    }

    /**
     * @param int|null $selected Tests that execute the changed functions; null — the whole suite.
     */
    public static function tests(?int $selected): self
    {
        return new self(EventKind::Tests, total: $selected);
    }

    public static function diffTests(int $functions, int $jobs): self
    {
        return new self(EventKind::DiffTests, done: 0, total: $functions, jobs: $jobs);
    }

    public static function diffTest(string $key, int $done, int $total): self
    {
        return new self(EventKind::DiffTest, subject: $key, done: $done, total: $total);
    }

    public static function diffDone(int $done, int $total): self
    {
        return new self(EventKind::DiffDone, done: $done, total: $total);
    }

    public static function stepDone(int $pass, string $rule, int $kept, int $rolledBack, int $gain, ?string $error): self
    {
        return new self(EventKind::StepDone, subject: $rule, pass: $pass, kept: $kept, rolledBack: $rolledBack, gain: $gain, error: $error);
    }

    public static function finalTests(int $round): self
    {
        return new self(EventKind::FinalTests, round: $round);
    }

    public static function warning(string $message): self
    {
        return new self(EventKind::Warning, subject: $message);
    }

    /**
     * Shown only with `-v`: the phases inside a verification.
     */
    public function detailed(): bool
    {
        return \in_array($this->kind, [EventKind::Rector, EventKind::PhpStan, EventKind::Tests, EventKind::DiffTests, EventKind::DiffTest, EventKind::DiffDone], true);
    }

    /**
     * The event as a line of the log; null — it makes no line (it only moves a counter).
     */
    public function text(): ?string
    {
        $subject = (string) $this->subject;
        $total = (int) $this->total;

        return match ($this->kind) {
            EventKind::Counting => "Counting the opcodes of {$total} file(s)",
            EventKind::OriginalTests => "Project tests on the original code ({$subject})",
            EventKind::CoverageMap => 'Coverage map of the project\'s tests',
            EventKind::Pass => "Pass {$this->pass}: {$subject}",
            EventKind::Rector => "Rector on {$total} file(s)",
            EventKind::Verifying => "Verifying {$total} file(s)" . ((int) $this->round > 1 ? ", round {$this->round}" : ''),
            EventKind::ReviewLeft => 'Verifying what is left of the step after the review',
            EventKind::PhpStan => 'PHPStan on the changed code',
            EventKind::Tests => $this->total === null
                ? 'Project tests on the changed code'
                : "Project tests on the changed code: {$total} that execute the changed functions",
            # Today's line of the parallel pool; one after another, the test of each function says enough.
            EventKind::DiffTests => $this->jobs > 1 ? "Differential tests of {$total} functions, {$this->jobs} at a time" : null,
            EventKind::DiffTest => "Differential test of {$subject}",
            EventKind::DiffDone => null,
            EventKind::StepDone => "Pass {$this->pass}: {$subject}: " . $this->result(),
            EventKind::FinalTests => 'Final run of the project\'s tests' . ((int) $this->round > 1 ? ", round {$this->round}" : ''),
            EventKind::Warning => $subject,
        };
    }

    /**
     * What a step left: kept / rolled back / the gain, or why it failed.
     */
    public function result(): string
    {
        if ($this->error !== null) {
            return 'failed: ' . $this->error;
        }

        if ($this->kept === 0 && $this->rolledBack === 0) {
            return 'no change';
        }

        return \sprintf('kept %d, rolled back %d, %s', $this->kept, $this->rolledBack, $this->gain === 0 ? 'no gain' : \sprintf('%+d opcodes', -$this->gain));
    }
}
