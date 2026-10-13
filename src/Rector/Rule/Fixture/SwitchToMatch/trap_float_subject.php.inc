<?php

namespace App\SwitchMatchTrap18;

function label(float $f): string
{
    switch ($f) {
        case 1:
            return 'one';
        case 2:
            return 'two';
        default:
            return 'many';
    }
}

?>
