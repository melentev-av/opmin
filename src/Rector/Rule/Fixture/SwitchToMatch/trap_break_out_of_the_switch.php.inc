<?php

namespace App\SwitchMatchTrap11;

function label(int $code): string
{
    switch ($code) {
        case 1:
            return 'one';
        case 2:
            break;
        default:
            return 'many';
    }

    return 'two';
}

?>
