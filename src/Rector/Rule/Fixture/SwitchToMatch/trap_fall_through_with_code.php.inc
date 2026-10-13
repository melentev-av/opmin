<?php

namespace App\SwitchMatchTrap9;

function label(int $code, array &$log): string
{
    switch ($code) {
        case 1:
            $log[] = 1;
        case 2:
            return 'one or two';
        default:
            return 'many';
    }
}

?>
