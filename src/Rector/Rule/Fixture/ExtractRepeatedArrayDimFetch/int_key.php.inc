<?php

namespace App\Dim4;

function first(array $list): int
{
    if (isset($list[0])) {
        return $list[0] + $list[0] * 2;
    }

    return 0;
}

?>
-----
<?php

namespace App\Dim4;

function first(array $list): int
{
    if (isset($list[0])) {
        $list0 = $list[0];
        return $list0 + $list0 * 2;
    }

    return 0;
}

?>
