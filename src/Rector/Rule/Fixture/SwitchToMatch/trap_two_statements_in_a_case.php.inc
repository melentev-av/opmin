<?php

namespace App\SwitchMatchTrap13;

function label(int $code, array &$log): string
{
    switch ($code) {
        case 1:
            $log[] = 1;
            return 'one';
        case 2:
            return 'two';
        default:
            return 'many';
    }
}

?>
