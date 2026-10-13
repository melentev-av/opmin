<?php

function opmin_sprintf_global(string $a, string $b): string
{
    return sprintf('%s/%s', $a, $b);
}

?>
-----
<?php

function opmin_sprintf_global(string $a, string $b): string
{
    return $a . '/' . $b;
}

?>
