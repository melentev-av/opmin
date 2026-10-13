<?php

namespace App\ClosureUse7;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () use ($s) {
            return static fn() => $s;
        };
    }
}

final class Item
{
}

?>
