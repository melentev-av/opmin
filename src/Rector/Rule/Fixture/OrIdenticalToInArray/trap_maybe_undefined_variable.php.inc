<?php

namespace App\InArray22;

function check(bool $b): bool
{
    if ($b) {
        $t = 'x';
    }

    return $t === 'a' || $t === 'b';
}

?>
