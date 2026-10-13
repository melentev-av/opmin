<?php

namespace App\Nullsafe25;

final class Node
{
    public array $items = [];

    public function __construct(public int $n = 0, public ?Node $next = null) {}

    public function size(int $extra): int
    {
        return $this->n + $extra;
    }
}

function n(bool $b): ?int
{
    if ($b) {
        $node = new Node();
    }

    return isset($node) ? $node->n : null;
}

?>
