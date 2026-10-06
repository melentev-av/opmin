<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

/**
 * Closures, a trait method, first-class callables and an anonymous class: checks opcode counting.
 */
final class Shapes
{
    use Greets;

    /**
     * @param list<int> $xs
     * @return list<int>
     */
    public function squares(array $xs): array
    {
        return array_map(function (int $x): int {
            return $x * $x;
        }, $xs);
    }

    /**
     * @param list<string> $words
     * @return list<int>
     */
    public function lengths(array $words): array
    {
        return array_map(strlen(...), $words);
    }

    public function counter(): object
    {
        return new class {
            private int $n = 0;

            public function next(): int
            {
                return ++$this->n;
            }
        };
    }
}
