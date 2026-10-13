<?php

namespace App\Nullsafe27;

final class Node
{
    public array $items = [];

    public function __construct(public int $n = 0, public ?Node $next = null) {}

    public function size(int $extra): int
    {
        return $this->n + $extra;
    }
}

function a(?array $a): mixed
{
    return $a !== null ? $a['k'] : null;
}

function b(?Node $node): ?Node
{
    return $node !== null ? $node : null;
}

function c(?Node $node): ?string
{
    return $node !== null ? \get_class($node) : null;
}

function d(?Node $node): ?int
{
    return $node !== null ? $node::class : null;
}

?>
