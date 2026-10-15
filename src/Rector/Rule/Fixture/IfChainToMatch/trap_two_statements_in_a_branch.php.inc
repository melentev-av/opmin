<?php

namespace App\IfMatchTrap3;

function label(string $code, array &$log): string
{
    if ($code === '1') {
        return 'one';
    }
    if ($code === '2') {
        $log[] = 2;
        return 'two';
    }
    if ($code === '3') {
        return 'three';
    }

    return 'many';
}

?>
