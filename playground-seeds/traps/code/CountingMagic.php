<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

/**
 * Trap: `__get()` counts reads, every read of `$this->value` returns another number.
 */
final class CountingMagic
{
    private int $reads = 0;

    public function __get(string $name): int
    {
        return ++$this->reads;
    }

    public function twice(): int
    {
        return $this->value + $this->value;
    }
}
