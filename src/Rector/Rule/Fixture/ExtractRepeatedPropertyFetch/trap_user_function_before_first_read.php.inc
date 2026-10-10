<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Lazy29
{
    private readonly int $v;

    public function run(): array
    {
        return [\Opmin\Tests\Fixture\PropertyFetch\init29($this), $this->v, $this->v];
    }

    public function init(): void
    {
        $this->v = 5;
    }
}

function init29(Lazy29 $lazy): int
{
    $lazy->init();
    return 0;
}

?>
