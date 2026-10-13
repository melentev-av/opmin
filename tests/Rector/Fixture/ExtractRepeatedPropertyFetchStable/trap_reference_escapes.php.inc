<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep12 { public function m(): void; }

final class St12
{
    private array $p = [1];
    public array $refs = [];

    public function __construct(private StDep12 $dep)
    {
    }

    public function run(): int
    {
        $a = \count($this->p);
        $this->dep->m();
        return $a + \count($this->p);
    }

    public function expose(): void
    {
        $this->refs[] = &$this->p;
    }
}

?>
