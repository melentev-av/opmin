<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep17 { public function m(): void; }

final class St17
{
    private int $p = 1;
    public int $q = 0 {
        set {
            $this->p = $value;
            $this->q = $value;
        }
    }

    public function __construct(private StDep17 $dep)
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
