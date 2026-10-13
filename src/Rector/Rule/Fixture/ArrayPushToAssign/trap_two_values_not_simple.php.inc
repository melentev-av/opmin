<?php

namespace App\ArrayPush14;

final class Pairs
{
    private array $items = [];

    public function calls(int $v): void
    {
        # Both values are evaluated before the first append.
        \array_push($this->items, $this->next($v), $this->next($v));
    }

    public function undefined(bool $c, int $v): void
    {
        if ($c) {
            $w = 1;
        }

        \array_push($this->items, $v, $w);
    }

    private function next(int $v): int
    {
        return \count($this->items) + $v;
    }
}

?>
