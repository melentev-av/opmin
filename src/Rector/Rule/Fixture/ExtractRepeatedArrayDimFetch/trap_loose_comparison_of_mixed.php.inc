<?php

namespace App\Dim12;

function same(array $row): bool
{
    if (isset($row['a'])) {
        return $row['a'] == 'x' && $row['a'] == 'y';
    }

    return false;
}

?>
