<?php

namespace App\ArrayPush2;

function pair(array $a, int $x): array
{
    \array_push($a, $x, 'end');

    return $a;
}

?>
-----
<?php

namespace App\ArrayPush2;

function pair(array $a, int $x): array
{
    $a[] = $x;
    $a[] = 'end';

    return $a;
}

?>
