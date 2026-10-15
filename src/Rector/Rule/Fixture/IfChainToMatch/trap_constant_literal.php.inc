<?php

namespace App\IfMatchTrap11;

function label(string $i): string
{
    if ($i === \FOO_ONE) {
        return 'one';
    }
    if ($i === '2') {
        return 'two';
    }

    return 'many';
}

?>
