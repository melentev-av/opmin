<?php

namespace App\SwitchMatchTrap6;

function label(int $code): string
{
    switch ($code) {
        case 'a':
            return 'a';
        case 'b':
            return 'b';
        default:
            return 'many';
    }
}

?>
