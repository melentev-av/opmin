<?php

namespace App\ClosureUse25;

#[\Opmin\Ignore]
final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () use ($s, $i) {
            return $i;
        };
    }
}

final class Item
{
}

?>
