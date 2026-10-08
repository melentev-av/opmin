<?php

namespace App\Count1;

function sum(array $items): int
{
    $sum = 0;
    for ($i = 0; $i < \count($items); $i++) {
        $sum += $items[$i];
    }

    return $sum;
}

?>
-----
<?php

namespace App\Count1;

function sum(array $items): int
{
    $sum = 0;
    for ($i = 0, $n = \count($items); $i < $n; $i++) {
        $sum += $items[$i];
    }

    return $sum;
}

?>
