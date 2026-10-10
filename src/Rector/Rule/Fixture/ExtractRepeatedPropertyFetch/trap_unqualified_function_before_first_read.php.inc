<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Lazy30
{
    private readonly int $v;

    public function run(string $s): array
    {
        return [strlen($s), $this->v, $this->v];
    }
}

?>
