<?php

namespace App\Dim10;

function run(array $row): array
{
    if (!isset($row['k'])) {
        return [];
    }

    $x = $row['k'];
    tick();
    return [$x, $row['k']];
}

function tick(): void
{
}

?>
