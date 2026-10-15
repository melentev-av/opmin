<?php

namespace App\IfMatchTrap19;

function label(string $i, array &$log): string
{
    if ($i === '1') {
        return 'one';
    } elseif ($i === '2') {
        return 'two';
    } else {
        $log[] = $i;
        return 'many';
    }
}

?>
