<?php

namespace App\Dim9;

function run(array &$a): array
{
    $a['k'] = 1;
    return [$a['k'], $a['k']];
}

?>
