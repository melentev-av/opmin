<?php

namespace App\ArrayPush11;

final class Bag
{
    # No default: array_push() throws "uninitialized ... by reference", `[]=` initializes it.
    private array $items;

    private static array $cache;

    public function add(int $v): void
    {
        \array_push($this->items, $v);
        \array_push(self::$cache, $v);
    }
}

?>
