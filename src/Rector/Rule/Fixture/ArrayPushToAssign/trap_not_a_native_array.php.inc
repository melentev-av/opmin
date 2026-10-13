<?php

namespace App\ArrayPush6;

/**
 * @param array<int> $a
 */
function untyped($a, int $v): mixed
{
    \array_push($a, $v);

    return $a;
}

function nullable(?array $a, int $v): ?array
{
    \array_push($a, $v);

    return $a;
}

function iterableValue(iterable $a, int $v): iterable
{
    \array_push($a, $v);

    return $a;
}

function offsets(\ArrayAccess $a, int $v): \ArrayAccess
{
    \array_push($a, $v);

    return $a;
}

?>
