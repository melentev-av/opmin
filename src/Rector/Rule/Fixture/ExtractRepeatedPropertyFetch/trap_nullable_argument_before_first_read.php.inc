<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Lazy35
{
    private readonly int $v;

    public function run(?string $s): array
    {
        return [\strlen($s), $this->v, $this->v];
    }
}

?>
