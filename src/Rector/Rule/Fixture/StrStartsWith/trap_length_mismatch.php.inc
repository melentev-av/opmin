<?php

namespace App\StartsWith4;

function lengths(string $s, int $n): array
{
    return [
        \substr($s, 0, 2) === 'abc',
        \substr($s, 0, 4) === 'abc',
        \substr($s, 1, 3) === 'abc',
        \substr($s, 0, $n) === 'abc',
        \substr($s, 0) === 'abc',
        # str_ends_with(): +2 opcodes on PHP 8.4+.
        \substr($s, -3) === 'abc',
        \substr($s, -3, 3) === 'abc',
    ];
}

function compared(string $s, string $t): bool
{
    return \substr($s, 0, 3) === $t;
}

?>
