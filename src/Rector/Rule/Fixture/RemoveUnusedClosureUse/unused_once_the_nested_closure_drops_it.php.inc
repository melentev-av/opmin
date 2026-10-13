<?php

namespace App\ClosureUse6;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () use ($s) {
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
-----
<?php

namespace App\ClosureUse6;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () {
            return static function () {
                return 1;
            };
        };
    }
}

final class Item
{
}

?>
