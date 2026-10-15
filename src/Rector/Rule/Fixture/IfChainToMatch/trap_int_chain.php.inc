<?php

namespace App\IfMatch1;

function label(int $code): string
{
    if ($code === 1) {
        return 'one';
    }
    if ($code === 2) {
        return 'two';
    }
    if ($code === 3) {
        return 'three';
    }

    return 'many';
}

?>
-----
<?php

namespace App\IfMatch1;

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
