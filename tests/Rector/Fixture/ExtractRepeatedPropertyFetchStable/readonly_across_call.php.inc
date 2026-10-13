<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDepRo { public function m(): void; }

final class StReadonly
{
    public function __construct(private StDepRo $dep, private readonly int $p)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }
}

?>
-----
<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDepRo { public function m(): void; }

final class StReadonly
{
    public function __construct(private StDepRo $dep, private readonly int $p)
    {
    }

    public function run(): int
    {
        $p = $this->p;
        $a = $p;
        $this->dep->m();
        return $a + $p;
    }
}

?>
