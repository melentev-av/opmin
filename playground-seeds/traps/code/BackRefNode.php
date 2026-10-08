<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

/**
 * Trap for ExtractRepeatedPropertyFetchRector: bar() clears the holder's property through a back
 * reference, so the second read of `$holder->foo` differs from the first.
 */
final class BackRefNode
{
    public ?BackRefHolder $owner = null;

    public function bar(): int
    {
        $this->owner?->reset();

        return 1;
    }
}
