<?php

namespace App\IfMatchTrap20;

function label(string $i, int $j): string
{
    if ($i === $j) {
        return 'same';
    }
    if ($i === '2') {
        return 'two';
    }

    return 'many';
}

?>
