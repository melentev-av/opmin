<?php

namespace App\Nullsafe29;

final class Node
{
    public array $items = [];

    public function __construct(public int $n = 0, public ?Node $next = null) {}

    public function size(int $extra): int
    {
        return $this->n + $extra;
    }
}

function n(?Node $node): mixed
{
    return $node ?: null;
}

function m(): ?int
{
    return null !== null ? null : null;
}

?>
