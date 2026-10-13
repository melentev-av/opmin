<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDepP { public function m(): void; }

class StOpen
{
    protected int $p = 1;

    public function __construct(private StDepP $dep)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }
}

final class StOpenChild extends StOpen
{
    public function set(int $v): void
    {
        $this->p = $v;
    }
}

?>
