<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep27 { public function m(): void; }

final class St27
{
    private int $p = 1;

    public function __construct(private StDep27 $dep)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }
}

function stArrayObject(object $o): void
{
    (new \ArrayObject($o))['p'] = 0;
}

?>
