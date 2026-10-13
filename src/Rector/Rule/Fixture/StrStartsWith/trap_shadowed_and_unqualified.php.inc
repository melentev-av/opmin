<?php

namespace App\StartsWith8;

function substr(string $s, int $offset, ?int $length = null): string
{
    return 'abc';
}

function shadowed(string $s): bool
{
    return substr($s, 0, 3) === 'abc';
}

?>
