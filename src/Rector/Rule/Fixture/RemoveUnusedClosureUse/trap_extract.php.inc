<?php

namespace App\ClosureUse12;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () use ($s, $a) {
            \extract(['x' => 1]);

            return 1;
        };
    }
}

final class Item
{
}

?>
