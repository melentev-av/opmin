<?php

namespace App\IfMatchTrap10;

function label($v): string
{
    if ($v === 1) {
        return 'int';
    }
    if ($v === '1') {
        return 'string';
    }
    if ($v === null) {
        return 'null';
    }

    return 'other';
}

?>
