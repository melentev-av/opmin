<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep19 { public function m(): void; }

abstract class StBase19
{
    public function __set(string $name, mixed $value): void
    {
    }
}

final class St19 extends StBase19
{
    private int $p = 1;

    public function __construct(private StDep19 $dep)
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
