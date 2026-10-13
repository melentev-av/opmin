<?php

namespace App\ClosureUse30;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () use ($s) {
            return static function () use ($s) {
                return $s;
            };
        };
    }
}

final class Item
{
}

?>
