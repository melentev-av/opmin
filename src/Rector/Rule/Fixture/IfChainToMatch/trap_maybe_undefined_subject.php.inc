<?php

namespace App\IfMatchTrap14;

function label(bool $b): string
{
    if ($b) {
        $i = 1;
    }
    if ($i === 1) {
        return 'one';
    }
    if ($i === 2) {
        return 'two';
    }

    return 'many';
}

?>
