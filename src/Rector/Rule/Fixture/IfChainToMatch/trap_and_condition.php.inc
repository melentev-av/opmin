<?php

namespace App\IfMatchTrap18;

function label(int $i, bool $b): string
{
    if ($i === 1 && $b) {
        return 'one';
    }
    if ($i === 2) {
        return 'two';
    }

    return 'many';
}

?>
