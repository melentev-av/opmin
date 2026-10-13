<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep25 { public function m(): void; }

final class St25
{
    

    public function __construct(private StDep25 $dep, private int &$p = 1)
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
