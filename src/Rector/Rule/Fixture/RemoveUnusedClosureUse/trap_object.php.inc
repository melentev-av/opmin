<?php

namespace App\ClosureUse17;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () use ($item, $i) {
            return $i;
        };
    }
}

final class Item
{
}

?>
