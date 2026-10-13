<?php

namespace App\ArrayPush8;

function spread(array $a, array $values): array
{
    \array_push($a, ...$values);

    return $a;
}

function nothing(array $a): array
{
    \array_push($a);

    return $a;
}

?>
