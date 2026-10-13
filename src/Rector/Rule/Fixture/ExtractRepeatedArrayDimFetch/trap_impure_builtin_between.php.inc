<?php

namespace App\Dim15;

function clock(array $row): int
{
    if (isset($row['pad'])) {
        return $row['pad'] + \time() + $row['pad'];
    }

    return 0;
}

function autoload(array $row, string $class): int
{
    if (isset($row['pad'])) {
        return $row['pad'] + (int) \class_exists($class) + $row['pad'];
    }

    return 0;
}

function deprecation(array $row, int $byte): int
{
    if (isset($row['pad'])) {
        return $row['pad'] + \strlen(\chr($byte)) + $row['pad'];
    }

    return 0;
}

?>
