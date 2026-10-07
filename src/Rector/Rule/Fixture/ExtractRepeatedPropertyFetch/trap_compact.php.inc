<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Box14
{
    public function __construct(public readonly int $v) {}

    public function vars(): array
    {
        $sum = $this->v + $this->v;
        return compact('sum');
    }
}

?>
