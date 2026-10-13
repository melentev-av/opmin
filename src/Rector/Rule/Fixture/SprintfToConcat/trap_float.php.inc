<?php

namespace App\Sprintf6;

function ratio(float $f, string $s): string
{
    # sprintf prints a float with `precision`, the cast with `serialize_precision`.
    return \sprintf('%s-%s', $f, $s);
}

function either(int|float $n, string $s): string
{
    return \sprintf('%s-%s', $n, $s);
}

function number(float $f, string $s): string
{
    return \sprintf('%d-%s', $f, $s);
}

?>
