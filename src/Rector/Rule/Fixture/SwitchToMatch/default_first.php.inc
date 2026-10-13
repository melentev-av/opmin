<?php

namespace App\SwitchMatch5;

function label(int $code): string
{
    switch ($code) {
        default:
            return 'many';
        case 1:
            return 'one';
        case 2:
            return 'two';
        case 3:
            return 'three';
    }
}

?>
-----
<?php

namespace App\SwitchMatch5;

function label(int $code): string
{
    return match ($code) {
        default => 'many',
        1 => 'one',
        2 => 'two',
        3 => 'three',
    };
}

?>
