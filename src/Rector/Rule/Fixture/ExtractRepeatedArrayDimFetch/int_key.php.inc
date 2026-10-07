<?php

namespace App\Dim4;

function first(): int
{
    $list = [10, 20];
    return $list[0] + $list[0] + $list[1];
}

?>
-----
<?php

namespace App\Dim4;

function first(): int
{
    $list = [10, 20];
    $list0 = $list[0];
    return $list0 + $list0 + $list[1];
}

?>
