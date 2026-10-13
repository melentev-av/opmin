<?php

namespace App\Nullsafe6;

final class Node
{
    public array $items = [];

    public function __construct(public int $n = 0, public ?Node $next = null) {}

    public function size(int $extra): int
    {
        return $this->n + $extra;
    }
}

function first(?Node $node): mixed
{
    return $node !== null ? $node->next?->items['first'] : null;
}

?>
-----
<?php

namespace App\Nullsafe6;

final class Node
{
    public array $items = [];

    public function __construct(public int $n = 0, public ?Node $next = null) {}

    public function size(int $extra): int
    {
        return $this->n + $extra;
    }
}

function first(?Node $node): mixed
{
    return $node?->next?->items['first'];
}

?>
