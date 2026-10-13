<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep15 { public function m(): void; }

final class St15
{
    private int $p = 1;

    public function __construct(private StDep15 $dep)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }

    public function &ref(): int
    {
        return $this->p;
    }
}

?>
