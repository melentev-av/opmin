<?php

namespace App\ClosureUse3;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        $map = ['a' => 1, 'b' => [2.5, null]];
        $map[$s] = $i;

        return function () use ($map, $s) {
            return $s;
        };
    }
}

final class Item
{
}

?>
-----
<?php

namespace App\ClosureUse3;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        $map = ['a' => 1, 'b' => [2.5, null]];
        $map[$s] = $i;

        return function () use ($s) {
            return $s;
        };
    }
}

final class Item
{
}

?>
