<?php

namespace App\SwitchMatchTrap4;

/**
 * @param int $code
 */
function label($code): string
{
    switch ($code) {
        case 1:
            return 'one';
        case 2:
            return 'two';
        default:
            return 'many';
    }
}

?>
