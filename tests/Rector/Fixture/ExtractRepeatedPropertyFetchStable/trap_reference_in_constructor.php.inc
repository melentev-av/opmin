<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep11 { public function m(): void; }

final class St11
{
    private int $p = 1;
    private int $q = 0;

    public function __construct(private StDep11 $dep)
    {
        $r = &$this->p;
        $r = 2;
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }
}

?>
