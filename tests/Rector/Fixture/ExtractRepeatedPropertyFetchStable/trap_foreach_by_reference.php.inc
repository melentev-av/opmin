<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep13 { public function m(): void; }

final class St13
{
    private array $p = [1];

    public function __construct(private StDep13 $dep)
    {
        foreach ($this->p as &$v) {
            $v++;
        }
    }

    public function run(): int
    {
        $a = \count($this->p);
        $this->dep->m();
        return $a + \count($this->p);
    }
}

?>
