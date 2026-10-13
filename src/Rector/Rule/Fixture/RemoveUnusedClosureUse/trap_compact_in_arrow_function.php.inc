<?php

namespace App\ClosureUse10;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () use ($s, $i) {
            return (static fn() => \compact('s'))();
        };
    }
}

final class Item
{
}

?>
