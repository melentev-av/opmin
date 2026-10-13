<?php

namespace App\IfMatch8;

function label(int $code): mixed
{
    if ($code === 1) {
        return 'one';
    }
    if ($code === 2) {
        return 'two';
    }

    return 'many';
}

?>
-----
<?php

namespace App\IfMatch8;

function label(int $code): mixed
{
    return match ($code) {
        1 => 'one',
        2 => 'two',
        default => 'many',
    };
}

?>
