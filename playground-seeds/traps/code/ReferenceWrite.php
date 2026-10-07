<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

/**
 * Trap: the property changes through a reference between the reads.
 */
final class ReferenceWrite
{
    private int $foo = 1;

    public function run(): int
    {
        $a = $this->foo;
        $ref = &$this->foo;
        $ref = 5;

        return $a + $this->foo;
    }
}
