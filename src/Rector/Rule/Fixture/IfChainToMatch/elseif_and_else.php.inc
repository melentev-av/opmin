<?php

namespace App\IfMatch3;

function size(string $n): string
{
    if ($n === '0') {
        return 'none';
    } elseif ($n === '1') {
        return 'one';
    } elseif ($n === '-1') {
        return 'minus one';
    } else {
        return 'some';
    }
}

?>
-----
<?php

namespace App\IfMatch3;

function size(string $n): string
{
    return match ($n) {
        '0' => 'none',
        '1' => 'one',
        '-1' => 'minus one',
        default => 'some',
    };
}

?>
