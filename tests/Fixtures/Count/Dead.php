<?php

// Fixture for opcode counting: the compiler evaluates `false && …` and a ternary of constants in an array
// literal, so a closure there (and the closure inside it) is never compiled and has no block in the dump.

declare(strict_types=1);

namespace Fixture\Count;

function dead(int $a): array
{
    return [$a, null ? (fn(int $b): \Closure => fn(): int => $b) : null, false && (fn(): int => 1),
        fn(): int => $a];
}

function alive(int $a): int
{
    return true ? $a : (fn(): int => 2)();
}
