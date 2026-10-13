<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

final class StCloned
{
    private array $items = [];

    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public function __clone()
    {
        $this->items[] = 0;
    }

    public function size(): int
    {
        $a = \count($this->items);
        $this->log();
        return $a + \count($this->items);
    }

    private function log(): void
    {
    }
}

?>
-----
<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

final class StCloned
{
    private array $items = [];

    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public function __clone()
    {
        $this->items[] = 0;
    }

    public function size(): int
    {
        $items = $this->items;
        $a = \count($items);
        $this->log();
        return $a + \count($items);
    }

    private function log(): void
    {
    }
}

?>
