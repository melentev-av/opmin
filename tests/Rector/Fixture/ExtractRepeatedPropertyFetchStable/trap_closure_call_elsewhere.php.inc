<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep5 { public function m(): void; }

final class St5
{
    private int $p = 1;

    public function __construct(private StDep5 $dep)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }
}

function stCall(St5 $o): void
{
    (fn() => $this->p = 3)->call($o);
}

?>
