<?php

namespace App\StartsWith9;

function unqualified(string $s): bool
{
    return substr($s, 0, 3) === 'abc' || strpos($s, 'abc') === 0;
}

?>
