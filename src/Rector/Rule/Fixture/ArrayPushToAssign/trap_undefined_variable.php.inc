<?php

namespace App\ArrayPush9;

function maybe(bool $c, int $v): array
{
    if ($c) {
        $a = [];
    }

    # array_push() throws a TypeError on null, `[]=` creates the array.
    \array_push($a, $v);

    return $a;
}

?>
