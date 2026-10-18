<?php

namespace App\MatchToIf3;

function truthy(int $n, string $s, ?object $o): string
{
    # `1 === true` is false, `if (1)` is taken.
    return match (true) {
        $n => 'n',
        default => 'none',
    };
}

function string(string $s): string
{
    return match (true) {
        $s => 'non-empty',
        default => 'empty',
    };
}

function untyped($v): string
{
    return match (true) {
        $v => 'v',
        default => 'none',
    };
}

function mixedConditions(int $n, $v): string
{
    return match (true) {
        $n > 0 => 'positive',
        $v => 'v',
        default => 'none',
    };
}

?>
