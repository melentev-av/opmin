<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep18 { public function m(): void; }

final class St18
{
    private int $p = 1;

    public function __construct(private StDep18 $dep)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }

    public function __set(string $name, mixed $value): void
    {
    }
}

?>
