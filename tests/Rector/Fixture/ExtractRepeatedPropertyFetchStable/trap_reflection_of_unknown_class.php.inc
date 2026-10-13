<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StDep9 { public function m(): void; }

final class St9
{
    private int $p = 1;

    public function __construct(private StDep9 $dep)
    {
    }

    public function run(): int
    {
        $a = $this->p;
        $this->dep->m();
        return $a + $this->p;
    }
}

function stReflectAny(object $o, string $class): void
{
    (new \ReflectionClass($class))->getProperty('p')->setValue($o, 7);
}

?>
