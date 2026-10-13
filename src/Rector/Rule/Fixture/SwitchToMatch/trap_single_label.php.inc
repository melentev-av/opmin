<?php

namespace App\SwitchMatchTrap16;

function label(int $code): string
{
    switch ($code) {
        case 1:
            return 'one';
        default:
            return 'many';
    }
}

?>
