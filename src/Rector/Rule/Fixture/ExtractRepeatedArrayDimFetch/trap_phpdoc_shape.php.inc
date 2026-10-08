<?php

namespace App\Dim7;

/**
 * @param array{k: int} $a
 */
function twice(array $a): array
{
    return [$a['k'], $a['k']];
}

?>
