<?php

namespace App\ArrayPush13;

function assigned(array $a): array
{
    \array_push($a, $a = []);

    return $a;
}

function reads(array $a): array
{
    \array_push($a, \count($a));

    return $a;
}

function increments(array $a, int $i): array
{
    \array_push($a, $i++);

    return $a;
}

?>
