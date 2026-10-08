<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Lazy36
{
    private readonly int $v;

    public function run(float $times): array
    {
        return [\str_repeat('x', $times), $this->v, $this->v];
    }
}

?>
