<?php

namespace App\ClosureUse19;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () use ($a, $i) {
            return $i;
        };
    }
}

final class Item
{
}

?>
