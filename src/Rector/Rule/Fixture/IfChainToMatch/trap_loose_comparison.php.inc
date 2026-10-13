<?php

namespace App\IfMatchTrap1;

function label($code): string
{
    if ($code == 1) {
        return 'one';
    }
    if ($code == 2) {
        return 'two';
    }

    return 'many';
}

?>
