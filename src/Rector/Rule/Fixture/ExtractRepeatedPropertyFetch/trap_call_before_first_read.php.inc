<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Lazy20
{
    private readonly int $v;

    public function run(): int
    {
        return $this->init() + $this->v + $this->v;
    }

    private function init(): int
    {
        $this->v = 5;
        return 0;
    }
}

?>
