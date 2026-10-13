<?php

namespace App\ClosureUse16;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () use ($s) {
            \parse_str('s=1');

            return 1;
        };
    }
}

final class Item
{
}

?>
