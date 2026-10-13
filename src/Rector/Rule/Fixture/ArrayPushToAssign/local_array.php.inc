<?php

namespace App\ArrayPush1;

function add(array $items, string $item): array
{
    // keep the item
    \array_push($items, $item);

    return $items;
}

function upper(array $strings): array
{
    $out = [];
    foreach ($strings as $s) {
        \array_push($out, \strtoupper((string) $s));
    }

    return $out;
}

?>
-----
<?php

namespace App\ArrayPush1;

function add(array $items, string $item): array
{
    // keep the item
    $items[] = $item;

    return $items;
}

function upper(array $strings): array
{
    $out = [];
    foreach ($strings as $s) {
        $out[] = \strtoupper((string) $s);
    }

    return $out;
}

?>
