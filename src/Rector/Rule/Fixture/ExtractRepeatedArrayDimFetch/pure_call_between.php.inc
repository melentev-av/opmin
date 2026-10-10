<?php

namespace App\Dim13;

function width(array $row, string $label): int
{
    if (isset($row['pad'])) {
        return $row['pad'] + \strlen($label) + $row['pad'];
    }

    return 0;
}

?>
-----
<?php

namespace App\Dim13;

function width(array $row, string $label): int
{
    if (isset($row['pad'])) {
        $pad = $row['pad'];
        return $pad + \strlen($label) + $pad;
    }

    return 0;
}

?>
