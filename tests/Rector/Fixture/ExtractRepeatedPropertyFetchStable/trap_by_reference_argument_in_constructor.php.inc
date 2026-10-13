<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep14 { public function m(): void; }

final class St14
{
    private array $p = [2, 1];

    public function __construct(private StDep14 $dep)
    {
        \sort($this->p);
    }

    public function run(): int
    {
        $a = \count($this->p);
        $this->dep->m();
        return $a + \count($this->p);
    }
}

?>
