<?php

namespace App\ClosureUseDoc21;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        if ($b) {
            $t = 1;
        }

        /** @var int $t */
        return static function () use ($t, $i) {
            return $i;
        };
    }
}

final class Item
{
}

?>
