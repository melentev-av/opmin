<?php

namespace App\Dim8;

function run(): int
{
    $a = ['k' => 1];
    $x = $a['k'];
    $a['k'] = 2;
    return $x + $a['k'];
}

?>
