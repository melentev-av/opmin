<?php

namespace App\SwitchMatchTrap23;

function label(int $code): string
{
    switch ($code) {
        case 1: {
            return 'one';
        }
        case 2:
            return 'two';
        default:
            return 'many';
    }
}

?>
