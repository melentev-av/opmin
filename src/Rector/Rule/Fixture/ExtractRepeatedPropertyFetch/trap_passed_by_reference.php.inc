<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Sorter23
{
    private array $items = [3, 1, 2];

    public function run(): array
    {
        $before = $this->items;
        sort($this->items);
        return [$before, $this->items];
    }
}

?>
