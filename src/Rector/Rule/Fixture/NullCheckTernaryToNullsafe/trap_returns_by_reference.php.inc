<?php

namespace App\Nullsafe28;

final class Node
{
    public array $items = [];

    public function __construct(public int $n = 0, public ?Node $next = null) {}

    public function size(int $extra): int
    {
        return $this->n + $extra;
    }
}

function &n(?Node $node): mixed
{
    $value = $node !== null ? $node->n : null;
    return $value;
}

function m(): \Closure
{
    return static fn&(?Node $node): mixed => $node !== null ? $node->n : null;
}

?>
