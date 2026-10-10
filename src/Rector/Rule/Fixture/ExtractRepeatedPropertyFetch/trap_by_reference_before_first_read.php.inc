<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Lazy34
{
    private readonly int $v;

    public function run(string $s): array
    {
        return [\preg_match('/x/', $s, $m), $m, $this->v, $this->v];
    }
}

?>
