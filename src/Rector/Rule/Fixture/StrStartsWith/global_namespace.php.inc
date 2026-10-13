<?php

function opmin_starts_with_global(string $s): bool
{
    return substr($s, 0, 2) === 'ab';
}

?>
-----
<?php

function opmin_starts_with_global(string $s): bool
{
    return \str_starts_with($s, 'ab');
}

?>
