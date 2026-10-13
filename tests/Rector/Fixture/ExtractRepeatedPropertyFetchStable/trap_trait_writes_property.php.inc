<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDepT2 { public function m(): void; }

trait StTraitWriter
{
    public function set(int $v): void
    {
        $this->p = $v;
    }
}

final class StT2
{
    use StTraitWriter;

    private int $p = 1;

    public function __construct(private StDepT2 $dep)
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
