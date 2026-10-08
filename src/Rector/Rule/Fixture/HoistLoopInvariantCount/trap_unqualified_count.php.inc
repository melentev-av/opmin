<?php

namespace App\Count8;

function sum(array $items): int
{
    $sum = 0;
    for ($i = 0; $i < count($items); $i++) {
        $sum += $items[$i];
    }

    return $sum;
}

?>
