<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDepT1 { public function m(): void; }

trait StTraitP
{
    private int $p = 1;
}

final class StT1
{
    use StTraitP;

    public function __construct(private StDepT1 $dep)
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
