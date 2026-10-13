<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep21 { public function m(): void; }

final class St21
{
    private int $p = 1;

    public function __construct(private StDep21 $dep)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }

    public function __unserialize(array $data): void
    {
        $this->p = $data['p'];
    }

    public function restore(array $data): void
    {
        $this->__unserialize($data);
    }
}

?>
