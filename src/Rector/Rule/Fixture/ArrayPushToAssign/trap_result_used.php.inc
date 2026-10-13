<?php

namespace App\ArrayPush5;

function counted(array $a, int $v): int
{
    $n = \array_push($a, $v);

    return $n + \array_push($a, $v);
}

function silenced(array $a, int $v): array
{
    @\array_push($a, $v);

    return $a;
}

?>
