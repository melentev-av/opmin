<?php

namespace App\ArrayPush19;

function logical(array $a, bool $x, bool $y): array
{
    # `$a[] = $x and $y` would append `$x` only.
    \array_push($a, $x and $y);
    \array_push($a, $x ? 'yes' : 'no');

    return $a;
}

?>
-----
<?php

namespace App\ArrayPush19;

function logical(array $a, bool $x, bool $y): array
{
    # `$a[] = $x and $y` would append `$x` only.
    $a[] = ($x and $y);
    $a[] = $x ? 'yes' : 'no';

    return $a;
}

?>
