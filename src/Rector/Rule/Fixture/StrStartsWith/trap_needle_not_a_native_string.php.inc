<?php

namespace App\StartsWith6;

function nullable(string $s, ?string $prefix): bool
{
    return \strpos($s, $prefix) === 0;
}

function number(string $s, int $prefix): bool
{
    return \strpos($s, $prefix) === 0;
}

/**
 * @param string $prefix
 */
function untyped(string $s, $prefix): bool
{
    return \strpos($s, $prefix) === 0;
}

?>
