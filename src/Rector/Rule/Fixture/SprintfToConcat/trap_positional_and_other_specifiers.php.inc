<?php

namespace App\Sprintf11;

function formats(string $s, int $i): array
{
    return [
        \sprintf('%1$s-%1$s', $s),
        \sprintf('%2$s-%1$s', $s, $s),
        \sprintf('%x-%s', $i, $s),
        \sprintf('%u-%s', $i, $s),
        \sprintf('%c-%s', $i, $s),
        \sprintf('%f-%s', $i, $s),
        \sprintf('%S-%s', $s, $s),
    ];
}

?>
