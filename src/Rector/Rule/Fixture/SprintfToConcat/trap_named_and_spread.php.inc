<?php

namespace App\Sprintf16;

function named(string $a): string
{
    return \sprintf(format: '%s', values: $a);
}

/**
 * @param list<string> $values
 */
function spread(array $values): string
{
    return \sprintf('%s-%s', ...$values);
}

function firstClass(): \Closure
{
    return \sprintf(...);
}

?>
