<?php

namespace App\Count6;

function count(array $a): int
{
    return 1;
}

function run(array $a): int
{
    $k = 0;
    for ($i = 0; $i < count($a); $i++) {
        $k++;
    }

    return $k;
}

?>
