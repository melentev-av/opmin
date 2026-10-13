<?php

namespace App\Nullsafe26;

final class Node
{
    public array $items = [];

    public function __construct(public int $n = 0, public ?Node $next = null) {}

    public function size(int $extra): int
    {
        return $this->n + $extra;
    }
}

function n(?Node $node, Node $other): ?int
{
    return $node !== null ? $other->n : null;
}

?>
