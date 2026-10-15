<?php

namespace App\IfMatchTrap2;

function label(string $code): string
{
    if ($code !== 1) {
        return 'not one';
    }
    if ($code === '2') {
        return 'two';
    }

    return 'one';
}

?>
