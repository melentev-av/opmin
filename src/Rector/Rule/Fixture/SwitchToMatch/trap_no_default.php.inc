<?php

namespace App\SwitchMatchTrap10;

function label(int $code, array &$log): ?string
{
    switch ($code) {
        case 1:
            return 'one';
        case 2:
            return 'two';
    }
    $log[] = $code;

    return null;
}

?>
