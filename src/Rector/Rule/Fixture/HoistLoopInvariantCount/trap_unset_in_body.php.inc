<?php

namespace App\Count7;

function run(array $a): int
{
    $k = 0;
    for ($i = 0; $i < \count($a); $i++) {
        unset($a[$i]);
        $k++;
    }

    return $k;
}

?>
