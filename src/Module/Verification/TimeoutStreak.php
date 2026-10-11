<?php

declare(strict_types=1);

namespace Opmin\Module\Verification;

/**
 * Inputs in a row the original version times out on during one differential test: each costs
 * `call_timeout_ms` and says nothing, so enough of them stop the test. Inputs the original runs out of
 * `memory_limit` on count in the same row: each costs new workers and says nothing either (#62).
 * Never after a failure: its shrink candidates run through the same check and must all run.
 *
 * @internal
 */
final class TimeoutStreak
{
    private int $inRow = 0;
    private int $memoryInRow = 0;
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
        return $this->expensive(false);
    }

    /**
     * The original ran out of `memory_limit` on an input.
     *
     * @return bool The test stops.
     */
    public function outOfMemory(): bool
    {
        return $this->expensive(true);
    }

    /**
     * The original ran an input to its end.
     */
    public function ran(): void
    {
        $this->stopped or $this->inRow = $this->memoryInRow = 0;
    }

    /**
     * Inputs of the current row (the one that stopped the test, once stopped) the original ran out of
     * memory on; the others timed out.
     */
    public function outOfMemoryInRow(): int
    {
        return $this->memoryInRow;
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

    private function expensive(bool $memory): bool
    {
        if ($this->stopped) {
            return true;
        }

        $memory and ++$this->memoryInRow;
        ++$this->inRow >= $this->limit && !$this->failed and $this->stopped = true;

        return $this->stopped;
    }
}
