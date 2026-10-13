<?php

namespace App\StartsWith5;

/**
 * @param string $s
 */
function untyped($s): bool
{
    return \substr($s, 0, 3) === 'abc';
}

function nullable(?string $s): bool
{
    return \substr($s, 0, 3) === 'abc' || \strpos($s, 'abc') === 0;
}

function stringable(\Stringable $s): bool
{
    return \substr($s, 0, 3) === 'abc' || \strpos($s, 'abc') === 0;
}

function number(int $i): bool
{
    return \substr($i, 0, 2) === '12' || \strpos($i, '12') === 0;
}

?>
