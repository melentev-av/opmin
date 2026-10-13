<?php

namespace App\ClosureUse18;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () use ($v, $i) {
            return $i;
        };
    }
}

final class Item
{
}

?>
