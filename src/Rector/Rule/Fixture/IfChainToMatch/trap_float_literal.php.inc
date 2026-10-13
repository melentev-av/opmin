<?php

namespace App\IfMatchTrap12;

function label(float $f): string
{
    if ($f === 1.5) {
        return 'one';
    }
    if ($f === 2.5) {
        return 'two';
    }

    return 'many';
}

?>
