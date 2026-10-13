<?php

namespace App\ClosureUse23;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return #[\Opmin\Ignore(rules: ['closure_use'])] static function () use ($s, $i) {
            return $i;
        };
    }
}

final class Item
{
}

?>
