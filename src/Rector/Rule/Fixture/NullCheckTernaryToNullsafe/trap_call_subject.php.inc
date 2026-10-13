<?php

namespace App\Nullsafe20;

final class Node
{
    public array $items = [];

    public function __construct(public int $n = 0, public ?Node $next = null) {}

    public function size(int $extra): int
    {
        return $this->n + $extra;
    }
}

final class Tree
{
    public function root(): ?Node
    {
        return null;
    }

    public function n(): ?int
    {
        return $this->root() !== null ? $this->root()->n : null;
    }
}

?>
