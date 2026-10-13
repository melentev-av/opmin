<?php

namespace App\ClosureUse14;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () use ($s) {
            return eval('return $s;');
        };
    }
}

final class Item
{
}

?>
