<?php

namespace App\SwitchMatch3;

function weight(string $unit, int $n)
{
    switch ($unit) {
        case 'kg':
            return $n * 1000;
        case 'g':
            return $n;
    }

    return -1;
}

?>
-----
<?php

namespace App\SwitchMatch3;

function weight(string $unit, int $n)
{
    return match ($unit) {
        'kg' => $n * 1000,
        'g' => $n,
        default => -1,
    };
}

?>
