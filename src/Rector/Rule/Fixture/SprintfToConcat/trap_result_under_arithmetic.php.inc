<?php

namespace App\Sprintf22;

function arithmetic(int $i, int $j, string $s): array
{
    # php-parser ranks `.` with `+`/`-` (pre-PHP 8): it would print `$i . $j + 1`, which PHP 8
    # reads as `$i . ($j + 1)`.
    return [
        \sprintf('%d%d', $i, $j) + 1,
        $i - \sprintf('%d%d', $i, $j),
        \sprintf('%d%d', $i, $j) << 1,
        $i >> \sprintf('%d%d', $i, $j),
        \sprintf('%s', $s . $s) + 1,
    ];
}

function concatUnderArithmetic(bool $c, int $i, int $j): string
{
    return \sprintf('%d-%d', $c ? ($i . $j) + 1 : 0, $i);
}

?>
