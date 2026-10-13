<?php

namespace App\IfMatchTrap13;

function label(int $i): string
{
    if ($i === 1) {
        return 'one';
    }

    return 'many';
}

?>
