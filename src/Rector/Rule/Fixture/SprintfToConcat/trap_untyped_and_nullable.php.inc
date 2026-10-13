<?php

namespace App\Sprintf8;

/**
 * @param string $a
 */
function untyped($a, string $b): string
{
    return \sprintf('%s-%s', $a, $b);
}

function nullable(?string $a, string $b): string
{
    return \sprintf('%s-%s', $a, $b);
}

function flag(bool $a, string $b): string
{
    return \sprintf('%s-%s', $a, $b);
}

function mixedValue(mixed $a, string $b): string
{
    return \sprintf('%s-%s', $a, $b);
}

?>
