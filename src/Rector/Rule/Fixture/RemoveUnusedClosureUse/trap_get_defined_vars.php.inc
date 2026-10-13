<?php

namespace App\ClosureUse11;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () use ($s) {
            return \get_defined_vars();
        };
    }
}

final class Item
{
}

?>
