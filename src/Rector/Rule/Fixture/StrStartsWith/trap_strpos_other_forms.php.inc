<?php

namespace App\StartsWith7;

function forms(string $s): array
{
    return [
        \strpos($s, 'a', 1) === 0,
        \strpos($s, 'a') === 0.0,
        \strpos($s, 'a') === 1,
        \strpos($s, 'a') !== false,
        \stripos($s, 'a') === 0,
        \strrpos($s, 'a') === 0,
        \mb_strpos($s, 'a') === 0,
        \mb_substr($s, 0, 1) === 'a',
    ];
}

?>
