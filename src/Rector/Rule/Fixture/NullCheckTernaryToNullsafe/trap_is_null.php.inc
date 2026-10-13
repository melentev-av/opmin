<?php

namespace App\Nullsafe24;

final class Node
{
    public array $items = [];

    public function __construct(public int $n = 0, public ?Node $next = null) {}

    public function size(int $extra): int
    {
        return $this->n + $extra;
    }
}

function n(?Node $node): ?int
{
    return is_null($node) ? null : $node->n;
}

?>
