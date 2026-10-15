<?php

namespace App\SprintfPhp84;

function line(string $s, int $i): string
{
    return \sprintf('%s-%d', $s, $i);
}

?>
