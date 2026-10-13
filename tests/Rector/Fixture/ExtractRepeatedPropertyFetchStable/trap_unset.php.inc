<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep16 { public function m(): void; }

final class St16
{
    private int $p = 1;

    public function __construct(private StDep16 $dep)
    {
        unset($this->p);
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }
}

?>
