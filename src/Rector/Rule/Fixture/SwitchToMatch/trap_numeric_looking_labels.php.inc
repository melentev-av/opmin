<?php

namespace App\SwitchMatchTrap2;

function label(string $s): string
{
    switch ($s) {
        case 'a':
            return 'a';
        case '1e1':
            return 'ten';
        case ' 1':
            return 'one';
        default:
            return 'other';
    }
}

?>
