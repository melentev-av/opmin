<?php

namespace App\ArrayPush16;

function unqualified(array $a, int $v): array
{
    array_push($a, $v);

    return $a;
}

?>
