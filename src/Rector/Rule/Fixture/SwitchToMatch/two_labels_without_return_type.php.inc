<?php

namespace App\SwitchMatch8;

function label(int $code)
{
    switch ($code) {
        case 1:
            return 'one';
        case 2:
            return 'two';
        default:
            return 'many';
    }
}

?>
-----
<?php

namespace App\SwitchMatch8;

function label(int $code)
{
    return match ($code) {
        1 => 'one',
        2 => 'two',
        default => 'many',
    };
}

?>
