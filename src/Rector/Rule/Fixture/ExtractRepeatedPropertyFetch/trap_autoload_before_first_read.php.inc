<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Lazy33
{
    private readonly int $v;

    public function run(string $class): array
    {
        return [\class_exists($class), $this->v, $this->v];
    }
}

?>
