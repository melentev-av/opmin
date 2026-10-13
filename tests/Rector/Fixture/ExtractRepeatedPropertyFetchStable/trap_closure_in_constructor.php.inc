<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep6 { public function m(): void; }

final class St6
{
    private int $p = 1;
    private ?\Closure $reset = null;

    public function __construct(private StDep6 $dep)
    {
        $this->reset = function (): void {
            $this->p = 0;
        };
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }
}

?>
