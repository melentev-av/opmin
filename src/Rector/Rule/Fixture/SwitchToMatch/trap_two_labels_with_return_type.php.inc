<?php

namespace App\SwitchMatchTrap24;

function label(int $code): string
{
    switch ($code) {
        case 1:
            return 'one';
        case 2:
            return 'two';
        default:
            return 'many';
    }
}

?>
