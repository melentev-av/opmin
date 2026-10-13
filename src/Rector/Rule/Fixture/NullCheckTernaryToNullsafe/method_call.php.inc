<?php

namespace App\Nullsafe3;

final class Node
{
    public array $items = [];

    public function __construct(public int $n = 0, public ?Node $next = null) {}

    public function size(int $extra): int
    {
        return $this->n + $extra;
    }
}

function size(?Node $node, int $extra): ?int
{
    $size = $node !== null ? $node->size($extra) : null;

    return $size;
}

?>
-----
<?php

namespace App\Nullsafe3;

final class Node
{
    public array $items = [];

    public function __construct(public int $n = 0, public ?Node $next = null) {}

    public function size(int $extra): int
    {
        return $this->n + $extra;
    }
}

function size(?Node $node, int $extra): ?int
{
    $size = $node?->size($extra);

    return $size;
}

?>
