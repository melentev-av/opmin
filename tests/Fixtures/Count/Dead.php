<?php

// Fixture for opcode counting: the compiler evaluates `false && …` and a ternary of constants in an array
// literal, so a closure or an anonymous class there (and what is inside it) is never compiled: no dump block.

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

function deadClass(int $a): array
{
    return [$a, null ? new class { public function n(): \Closure { return fn(): int => 1; } } : null];
}
