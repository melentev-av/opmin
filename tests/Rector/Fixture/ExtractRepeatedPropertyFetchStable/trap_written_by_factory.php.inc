<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep23 { public function m(): void; }

final class St23
{
    private int $p = 1;

    public function __construct(private StDep23 $dep)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }

    public static function make(StDep23 $dep): self
    {
        $o = new self($dep);
        $o->p = 5;

        return $o;
    }
}

?>
