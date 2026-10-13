<?php

namespace App\SwitchMatchTrap8;

function label(int|string $v): string
{
    switch ($v) {
        case 'a':
            return 'a';
        case 'b':
            return 'b';
        default:
            return 'many';
    }
}

?>
