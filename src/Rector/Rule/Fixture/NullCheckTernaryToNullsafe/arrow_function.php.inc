<?php

namespace App\Nullsafe7;

final class Node
{
    public array $items = [];

    public function __construct(public int $n = 0, public ?Node $next = null) {}

    public function size(int $extra): int
    {
        return $this->n + $extra;
    }
}

function sizes(array $nodes): array
{
    return array_map(static fn(?Node $node): ?int => $node !== null ? $node->n : null, $nodes);
}

?>
-----
<?php

namespace App\Nullsafe7;

final class Node
{
    public array $items = [];

    public function __construct(public int $n = 0, public ?Node $next = null) {}

    public function size(int $extra): int
    {
        return $this->n + $extra;
    }
}

function sizes(array $nodes): array
{
    return array_map(static fn(?Node $node): ?int => $node?->n, $nodes);
}

?>
