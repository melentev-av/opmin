<?php

namespace App\Sprintf10;

function formats(string $s, int $i): array
{
    return [
        \sprintf('%5s-%s', $s, $s),
        \sprintf('%-5s-%s', $s, $s),
        \sprintf('%05d-%s', $i, $s),
        \sprintf('%+d-%s', $i, $s),
        \sprintf('%.2s-%s', $s, $s),
        \sprintf("%'*5s-%s", $s, $s),
    ];
}

?>
