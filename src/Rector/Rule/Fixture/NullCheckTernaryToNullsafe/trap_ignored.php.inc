<?php

namespace App\Nullsafe30;

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
    #[\Opmin\Ignore(rules: ['nullsafe'])]
    public function a(?Node $node): ?int
    {
        return $node !== null ? $node->n : null;
    }

    /**
     * @opmin-ignore nullsafe
     */
    public function b(?Node $node): ?int
    {
        return $node !== null ? $node->n : null;
    }
}

?>
