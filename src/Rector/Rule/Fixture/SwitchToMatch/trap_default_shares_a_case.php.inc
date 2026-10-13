<?php

namespace App\SwitchMatchTrap14;

function label(int $code): string
{
    switch ($code) {
        case 1:
            return 'one';
        case 2:
        default:
            return 'many';
    }
}

?>
