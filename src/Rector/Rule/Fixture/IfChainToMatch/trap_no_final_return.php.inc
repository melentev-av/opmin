<?php

namespace App\IfMatchTrap8;

function label(string $i, array &$log): string
{
    if ($i === '1') {
        return 'one';
    }
    if ($i === '2') {
        return 'two';
    }
    $log[] = $i;

    return 'many';
}

?>
