<?php

namespace App\SwitchMatchTrap12;

function first(array $codes): string
{
    foreach ($codes as $code) {
        switch ((int) $code) {
            case 0:
                continue 2;
            case 1:
                return 'one';
            default:
                return 'many';
        }
    }

    return 'none';
}

?>
