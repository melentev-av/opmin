<?php

namespace App\Sprintf12;

function percent(string $s): string
{
    # ValueError: Missing format specifier at end of string.
    return \sprintf('%s-%s%', $s, $s);
}

?>
