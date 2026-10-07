<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

/**
 * Trap for HoistLoopInvariantCountRector: count() has a side effect.
 */
final class ShrinkingCountable implements \Countable
{
    private int $n = 3;

    public function count(): int
    {
        return $this->n--;
    }
}
