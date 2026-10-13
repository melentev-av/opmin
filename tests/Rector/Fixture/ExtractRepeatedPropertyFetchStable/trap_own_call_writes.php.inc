<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep3 { public function m(): void; }

final class St3
{
    private int $p = 1;

    public function __construct(private StDep3 $dep)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->touch();
        return $a + $this->p;
    }

    private function touch(): void
    {
        $this->p++;
    }
}

?>
