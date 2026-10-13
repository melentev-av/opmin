<?php

namespace App\ArrayPush7;

function three(array $a, int $x, int $y, int $z): array
{
    # Three appends cost as much as the call.
    \array_push($a, $x, $y, $z);

    return $a;
}

?>
