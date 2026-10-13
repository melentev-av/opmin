<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep4 { public function m(): void; }

final class St4
{
    private int $p = 1;

    public function __construct(private StDep4 $dep)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }
}

function stBind(St4 $o): void
{
    \Closure::bind(function (St4 $o): void { $o->p = 2; }, null, St4::class)($o);
}

?>
