<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep22 { public function m(): void; }

final class St22
{
    private int $p = 1;

    public function __construct(private StDep22 $dep)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }

    public function withP(int $p): self
    {
        $clone = clone $this;
        $clone->p = $p;

        return $clone;
    }
}

?>
