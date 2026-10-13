<?php

namespace App\ArrayPush15;

function array_push(array &$a, mixed ...$values): int
{
    return 0;
}

function shadowed(array $a, int $v): array
{
    array_push($a, $v);

    return $a;
}

?>
