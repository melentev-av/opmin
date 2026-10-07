<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Ref10
{
    private int $foo = 1;

    public function run(): int
    {
        $a = $this->foo;
        $ref = &$this->foo;
        $ref = 5;
        return $a + $this->foo;
    }
}

?>
