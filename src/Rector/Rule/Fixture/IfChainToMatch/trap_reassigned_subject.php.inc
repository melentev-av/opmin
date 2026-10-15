<?php

namespace App\IfMatchTrap7;

function label(string $i): string
{
    if ($i === '1') {
        return 'one';
    }
    $i = $i * 2;
    if ($i === '2') {
        return 'two';
    }

    return 'many';
}

?>
