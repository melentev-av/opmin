<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Lazy32 implements \Stringable
{
    private readonly int $v;

    public function run(): array
    {
        return [\str_contains($this, 'x'), $this->v, $this->v];
    }

    public function __toString(): string
    {
        $this->v = 5;
        return 'x';
    }
}

?>
