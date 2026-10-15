<?php

namespace App\IfMatchTrap17;

function label(array $vars): string
{
    \extract($vars);
    $i = 0;
    if ($i === '1') {
        return 'one';
    }
    if ($i === '2') {
        return 'two';
    }

    return 'many';
}

?>
