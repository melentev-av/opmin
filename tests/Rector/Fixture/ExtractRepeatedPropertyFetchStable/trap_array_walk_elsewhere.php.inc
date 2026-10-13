<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep26 { public function m(): void; }

final class St26
{
    private int $p = 1;

    public function __construct(private StDep26 $dep)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }
}

function stWalk(object $o): void
{
    \array_walk($o, static function (mixed &$v): void { $v = 0; });
}

?>
