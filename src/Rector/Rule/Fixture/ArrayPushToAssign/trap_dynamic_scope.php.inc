<?php

namespace App\ArrayPush17;

function extracted(array $a, array $vars): array
{
    \array_push($a, \extract($vars));

    return $a;
}

?>
