<?php

namespace App\Sprintf14;

function sprintf(string $format, mixed ...$values): string
{
    return 'mine';
}

function label(string $a, string $b): string
{
    return sprintf('%s-%s', $a, $b);
}

?>
