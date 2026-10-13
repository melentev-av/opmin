<?php

namespace App\Dim14;

function width(array $row, string $label): int
{
    if (isset($row['pad'])) {
        return $row['pad'] + \strcoll($label, 'x') + \strncmp($label, 'x', 1) + $row['pad'];
    }

    return 0;
}

?>
-----
<?php

namespace App\Dim14;

function width(array $row, string $label): int
{
    if (isset($row['pad'])) {
        $pad = $row['pad'];
        return $pad + \strcoll($label, 'x') + \strncmp($label, 'x', 1) + $pad;
    }

    return 0;
}

?>
