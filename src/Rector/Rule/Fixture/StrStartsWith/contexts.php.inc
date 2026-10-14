<?php

namespace App\StartsWith11;

function contexts(bool $flag, bool $c, string $s): array
{
    return [
        $flag and \substr($s, 0, 1) === 'a',
        (int) (\substr($s, 0, 1) !== 'a'),
        -(\strpos($s, 'a') !== 0),
        \substr($s, 0, 1) !== 'a' ? 1 : 2,
        \strpos($s, $c ? 'a' : 'b') === 0,
        \strpos($c ? $s : 'x', 'a') === 0,
    ];
}

?>
-----
<?php

namespace App\StartsWith11;

function contexts(bool $flag, bool $c, string $s): array
{
    return [
        $flag and \str_starts_with($s, 'a'),
        (int) (!\str_starts_with($s, 'a')),
        -(!\str_starts_with($s, 'a')),
        !\str_starts_with($s, 'a') ? 1 : 2,
        \str_starts_with($s, $c ? 'a' : 'b'),
        \str_starts_with($c ? $s : 'x', 'a'),
    ];
}

?>
