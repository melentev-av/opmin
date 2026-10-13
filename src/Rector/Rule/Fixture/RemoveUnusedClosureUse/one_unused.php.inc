<?php

namespace App\ClosureUse1;

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
-----
<?php

namespace App\ClosureUse1;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () use ($i) {
            return $i;
        };
    }
}

final class Item
{
}

?>
