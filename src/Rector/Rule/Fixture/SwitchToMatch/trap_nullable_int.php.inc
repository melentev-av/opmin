<?php

namespace App\SwitchMatchTrap5;

function label(?int $code): string
{
    switch ($code) {
        case 0:
            return 'zero';
        case 1:
            return 'one';
        default:
            return 'many';
    }
}

?>
