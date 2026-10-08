<?php

namespace App\Dim11;

function lineTotal(array $row): int
{
    if (isset($row['qty'], $row['price'])) {
        return $row['qty'] * $row['price'] + $row['qty'] * $row['qty'];
    }

    return 0;
}

?>
-----
<?php

namespace App\Dim11;

function lineTotal(array $row): int
{
    if (isset($row['qty'], $row['price'])) {
        $qty = $row['qty'];
        return $qty * $row['price'] + $qty * $qty;
    }

    return 0;
}

?>
