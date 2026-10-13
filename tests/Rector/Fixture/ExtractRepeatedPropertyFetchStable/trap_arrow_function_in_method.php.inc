<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep7 { public function m(): void; }

final class St7
{
    private int $p = 1;

    public function __construct(private StDep7 $dep)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }

    public function resetter(): \Closure
    {
        return fn() => $this->p = 0;
    }
}

?>
