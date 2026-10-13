<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep8 { public function m(): void; }

final class St8
{
    private int $p = 1;

    public function __construct(private StDep8 $dep)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }
}

function stReflect(St8 $o): void
{
    (new \ReflectionProperty(St8::class, 'p'))->setValue($o, 6);
}

?>
