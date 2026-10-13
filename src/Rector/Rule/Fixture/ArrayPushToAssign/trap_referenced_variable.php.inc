<?php

namespace App\ArrayPush10;

function aliased(array $a, int $v): array
{
    $alias = &$a;
    \array_push($a, $v);

    return $alias;
}

function captured(array $a, int $v): array
{
    $f = function () use (&$a): void {
        $a = [];
    };
    \array_push($a, $v);
    $f();

    return $a;
}

?>
