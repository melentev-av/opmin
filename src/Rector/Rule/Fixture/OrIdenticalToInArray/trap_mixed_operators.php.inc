<?php

namespace App\InArray28;

function check(string $s): bool
{
    return ($s === 'a' && $s === 'b') || $s !== 'c' || ($s !== 'd' || $s !== 'e');
}

?>
