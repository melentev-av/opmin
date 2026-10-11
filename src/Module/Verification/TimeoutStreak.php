<?php

declare(strict_types=1);

namespace Opmin\Module\Verification;

/**
 * Inputs in a row the original version times out on during one differential test: each costs
 * `call_timeout_ms` and says nothing, so enough of them stop the test. Never after a failure: its
 * shrink candidates run through the same check and must all run.
 *
 * @internal
 */
final class TimeoutStreak
{
    private int $inRow = 0;
    private bool $stopped = false;
    private bool $failed = false;

    /**
     * @param positive-int $limit Timeouts in a row that stop the test.
     */
    public function __construct(
        public readonly int $limit,
    ) {}

    /**
     * The original timed out on an input.
     *
     * @return bool The test stops.
     */
    public function timedOut(): bool
    {
        ++$this->inRow >= $this->limit && !$this->failed and $this->stopped = true;

        return $this->stopped;
    }

    /**
     * The original ran an input to its end.
     */
    public function ran(): void
    {
        $this->inRow = 0;
    }

    /**
     * An input failed the check: a difference or nondeterminism is being shrunk.
     */
    public function failed(): void
    {
        $this->failed = true;
    }

    public function stopped(): bool
    {
        return $this->stopped;
    }
}
