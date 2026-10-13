<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep20 { public function m(): void; }

final class St20
{
    private int $p = 1;

    public function __construct(private StDep20 $dep)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }

    public function reset(StDep20 $dep): void
    {
        $this->__construct($dep);
    }
}

?>
