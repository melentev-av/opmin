<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

/**
 * Trap for ExtractRepeatedArrayDimFetchRector: an ArrayAccess object, every `$o['k']` calls offsetGet().
 *
 * @implements \ArrayAccess<string, int>
 */
final class CountingOffsets implements \ArrayAccess
{
    private int $reads = 0;

    public function offsetExists(mixed $offset): bool
    {
        return true;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return ++$this->reads;
    }

    public function offsetSet(mixed $offset, mixed $value): void {}

    public function offsetUnset(mixed $offset): void {}
}
