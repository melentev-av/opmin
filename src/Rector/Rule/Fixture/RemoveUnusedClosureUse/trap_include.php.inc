<?php

namespace App\ClosureUse15;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () use ($s) {
            return include __DIR__ . '/template.php';
        };
    }
}

final class Item
{
}

?>
