<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Node8
{
    public ?Holder8 $owner = null;

    public function bar(): void
    {
        $this->owner?->reset();
    }
}

final class Holder8
{
    private ?Node8 $foo = null;

    public function reset(): void
    {
        $this->foo = null;
    }

    public function run(): void
    {
        $this->foo->bar();
        $this->foo->bar();
    }
}

?>
