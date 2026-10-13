<?php

namespace App\Nullsafe21;

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
    public ?Node $root = null;

    public function n(): ?int
    {
        return $this->root !== null ? $this->root->n : null;
    }
}

?>
