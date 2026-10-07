<?php

namespace App\Dim2;

function total(array $row): int
{
    if (isset($row['qty'])) {
        return $row['qty'] * $row['qty'];
    }

    return 0;
}

?>
-----
<?php

namespace App\Dim2;

function total(array $row): int
{
    if (isset($row['qty'])) {
        $qty = $row['qty'];
        return $qty * $qty;
    }

    return 0;
}

?>
