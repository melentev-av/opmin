<?php

namespace App\Count4;

function run(array $a): int
{
    $n2 = 0;
    for ($i = 0; $i < \count($a); $i++) {
        array_pop($a);
        $n2++;
    }

    return $n2;
}

?>
