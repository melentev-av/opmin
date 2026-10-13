<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep24 { public function m(): void; }

final class St24
{
    private int $p = 1;

    public function __construct(private StDep24 $dep)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }

    public function set(string $name, int $v): void
    {
        $this->$name = $v;
    }
}

?>
