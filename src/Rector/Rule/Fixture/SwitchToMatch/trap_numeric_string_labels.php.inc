<?php

namespace App\SwitchMatchTrap1;

function label(string $s): string
{
    switch ($s) {
        case '1':
            return 'one';
        case '10':
            return 'ten';
        default:
            return 'other';
    }
}

?>
