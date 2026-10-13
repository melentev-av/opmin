<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep28 { public function m(): void; }

final class St28
{
    private int $p = 1;

    public function __construct(private StDep28 $dep)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }

    public function zero(): void
    {
        foreach ($this as &$v) {
            $v = 0;
        }
    }
}

?>
