<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep2 { public function m(): void; }

final class St2
{
    private int $p = 1;

    public function __construct(private StDep2 $dep)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }

    public function set(int $v): void
    {
        $this->p = $v;
    }
}

?>
