<?php

namespace App\ClosureUse24;

final class Maker
{
    /** @opmin-ignore closure_use */
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () use ($s, $i) {
            return static function () use ($s) {
                return 1;
            };
        };
    }
}

final class Item
{
}

?>
