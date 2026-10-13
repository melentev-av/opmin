<?php

namespace App\IfMatch7;

function after(string $s): int
{
    $s = \trim($s);
    if ($s === 'x') {
        return 1;
    }
    if ($s === 'y') {
        return 2;
    }
    if ($s === 'w') {
        return 5;
    }

    return 3;
    if ($s === 'z') {
        return 4;
    }
}

?>
-----
<?php

namespace App\IfMatch7;

function after(string $s): int
{
    $s = \trim($s);
    return match ($s) {
        'x' => 1,
        'y' => 2,
        'w' => 5,
        default => 3,
    };
    if ($s === 'z') {
        return 4;
    }
}

?>
