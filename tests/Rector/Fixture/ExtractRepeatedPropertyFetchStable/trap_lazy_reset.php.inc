<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep10 { public function m(): void; }

final class St10
{
    private int $p = 1;

    public function __construct(private StDep10 $dep)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }
}

function stLazy(St10 $o): void
{
    (new \ReflectionClass(St10::class))->resetAsLazyGhost($o, static function (St10 $o): void {});
}

?>
