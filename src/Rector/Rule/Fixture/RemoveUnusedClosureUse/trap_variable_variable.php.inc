<?php

namespace App\ClosureUse13;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () use ($s, $v) {
            $name = 's';

            return $$name;
        };
    }
}

final class Item
{
}

?>
