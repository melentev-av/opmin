<?php

function opmin_array_push_global(array $a, int $v): array
{
    array_push($a, $v);

    return $a;
}

?>
-----
<?php

function opmin_array_push_global(array $a, int $v): array
{
    $a[] = $v;

    return $a;
}

?>
