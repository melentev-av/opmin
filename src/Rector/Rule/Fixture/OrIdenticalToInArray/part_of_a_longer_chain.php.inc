<?php

namespace App\InArray4;

function accept(bool $force, string $s, int $i, mixed $v): bool
{
    return $force || $s === 'a' || $s === 'b' || $i === 3 || $v === 1 || $v === 'x';
}

?>
-----
<?php

namespace App\InArray4;

function accept(bool $force, string $s, int $i, mixed $v): bool
{
    return $force || \in_array($s, ['a', 'b'], true) || $i === 3 || \in_array($v, [1, 'x'], true);
}

?>
