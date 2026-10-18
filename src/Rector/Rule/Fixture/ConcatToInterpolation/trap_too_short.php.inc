<?php

namespace App\Interpolate4;

function two(string $a, string $b): string
{
    return $a . $b;
}

function variableAndLiteral(string $a): string
{
    return $a . '/';
}

function literalsFolded(string $a): string
{
    return 'a' . 'b' . $a;
}

function emptyBetween(string $a, string $b): string
{
    return $a . '' . $b;
}

function literalsOnly(): string
{
    return 'a' . '/' . 'b';
}

function alreadyInterpolated(string $a, string $b): string
{
    return "{$a}/{$b}" . \strtoupper($a);
}

?>
