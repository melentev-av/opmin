<?php

namespace App\Sprintf9;

function number(string $s, string $t): string
{
    # %d of '1e3' is 1000, of 'abc' is 0.
    return \sprintf('%d-%s', $s, $t);
}

function either(int|string $k, string $t): string
{
    return \sprintf('%d-%s', $k, $t);
}

?>
