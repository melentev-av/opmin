<?php

namespace App\IfMatchMin1;

function label(int $code): string
{
    if ($code === 1 || $code === 2) {
        return 'low';
    }
    if ($code === 3) {
        return 'three';
    }

    return 'many';
}

?>
-----
<?php

namespace App\IfMatchMin1;

function label(int $code): string
{
    return match ($code) {
        1, 2 => 'low',
        3 => 'three',
        default => 'many',
    };
}

?>
