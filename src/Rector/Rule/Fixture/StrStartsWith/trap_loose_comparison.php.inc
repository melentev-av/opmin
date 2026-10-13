<?php

namespace App\StartsWith3;

function loose(string $s): array
{
    return [
        # '1e1' == '10': numeric strings compare as numbers.
        \substr($s, 0, 2) == '10',
        \substr($s, 0, 2) != '10',
        # false == 0.
        \strpos($s, 'a') == 0,
        \strpos($s, 'a') != 0,
    ];
}

?>
