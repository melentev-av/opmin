<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep1 { public function m(): void; }

final class St1
{
    private int $p = 1;

    public function __construct(private StDep1 $dep)
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

interface StDep1 { public function m(): void; }

final class St1
{
    private int $p = 1;

    public function __construct(private StDep1 $dep)
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
