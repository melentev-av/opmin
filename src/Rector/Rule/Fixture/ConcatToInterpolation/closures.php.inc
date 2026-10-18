<?php

namespace App\Interpolate8;

function outer(string $a, string $b): \Closure
{
    $label = $a . ':' . $b;

    return static fn(string $c): string => $label . '/' . $c;
}

function inner(array $items, string $sep): array
{
    return \array_map(static function (string $item) use ($sep): string {
        return $item . $sep . $item;
    }, $items);
}

?>
-----
<?php

namespace App\Interpolate8;

function outer(string $a, string $b): \Closure
{
    $label = "{$a}:{$b}";

    return static fn(string $c): string => "{$label}/{$c}";
}

function inner(array $items, string $sep): array
{
    return \array_map(static function (string $item) use ($sep): string {
        return "{$item}{$sep}{$item}";
    }, $items);
}

?>
