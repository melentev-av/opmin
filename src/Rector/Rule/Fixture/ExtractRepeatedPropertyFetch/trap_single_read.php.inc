<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Box18
{
    public function __construct(private readonly int $v) {}

    public function once(): int
    {
        return $this->v + 1;
    }
}

?>
