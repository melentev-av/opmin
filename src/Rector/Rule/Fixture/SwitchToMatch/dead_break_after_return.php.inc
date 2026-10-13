<?php

namespace App\SwitchMatch4;

function label(int $code): string
{
    switch ($code) {
        case 1:
            return 'one';
            break;
        case 2:
            return 'two';
            break;
        case 3:
            return 'three';
            break;
        default:
            return 'many';
    }
}

?>
-----
<?php

namespace App\SwitchMatch4;

function label(int $code): string
{
    return match ($code) {
        1 => 'one',
        2 => 'two',
        3 => 'three',
        default => 'many',
    };
}

?>
