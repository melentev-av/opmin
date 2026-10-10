<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Lazy37
{
    private readonly int $v;

    public function run(string $s, string $chars): array
    {
        return [\trim($s, $chars), $this->v, $this->v];
    }
}

?>
