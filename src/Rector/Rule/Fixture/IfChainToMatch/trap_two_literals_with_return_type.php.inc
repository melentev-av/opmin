<?php

namespace App\IfMatchTrap21;

function label(int $code): string
{
    if ($code === 1) {
        return 'one';
    }
    if ($code === 2) {
        return 'two';
    }

    return 'many';
}

?>
