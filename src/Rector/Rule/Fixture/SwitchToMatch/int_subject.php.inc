<?php

namespace App\SwitchMatch1;

function label(int $code): string
{
    switch ($code) {
        case 1:
            return 'one';
        case 2:
            return 'two';
        case -3:
            return 'minus three';
        default:
            return 'many';
    }
}

?>
-----
<?php

namespace App\SwitchMatch1;

function label(int $code): string
{
    return match ($code) {
        1 => 'one',
        2 => 'two',
        -3 => 'minus three',
        default => 'many',
    };
}

?>
