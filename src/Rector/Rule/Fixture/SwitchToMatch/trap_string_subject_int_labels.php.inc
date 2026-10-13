<?php

namespace App\SwitchMatchTrap7;

function label(string $s): string
{
    switch ($s) {
        case 1:
            return 'one';
        case 2:
            return 'two';
        default:
            return 'many';
    }
}

?>
