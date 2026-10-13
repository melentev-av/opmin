<?php

namespace App\IfMatchTrap6;

function label(int $a, int $b): string
{
    if ($a === 1) {
        return 'a';
    }
    if ($b === 2) {
        return 'b';
    }

    return 'none';
}

?>
