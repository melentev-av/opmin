<?php

namespace App\ClosureUse9;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () use ($s, $i) {
            return \compact('s', 'i');
        };
    }
}

final class Item
{
}

?>
