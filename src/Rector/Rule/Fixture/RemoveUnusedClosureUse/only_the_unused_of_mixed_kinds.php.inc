<?php

namespace App\ClosureUse4;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () use ($item, $s, &$i, $v) {
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

namespace App\ClosureUse4;

final class Maker
{
    public function make(string $s, int $i, ?int $ni, bool $b, array $a, $v, Item $item): \Closure
    {
        return static function () use ($item, &$i, $v) {
            return $i;
        };
    }
}

final class Item
{
}

?>
