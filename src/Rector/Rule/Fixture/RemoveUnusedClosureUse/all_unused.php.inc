<?php

namespace App\ClosureUse2;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function (int $k) use ($s, $ni, $b) {
            return $k * 2;
        };
    }
}

final class Item
{
}

?>
-----
<?php

namespace App\ClosureUse2;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function (int $k) {
            return $k * 2;
        };
    }
}

final class Item
{
}

?>
