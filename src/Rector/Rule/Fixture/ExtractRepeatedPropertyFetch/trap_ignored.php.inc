<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Ignored22
{
    public function __construct(private readonly int $v) {}

    #[\Opmin\Ignore]
    public function twice(): int
    {
        return $this->v + $this->v;
    }

    /** @opmin-ignore property_fetch */
    public function thrice(): int
    {
        return $this->v + $this->v + $this->v;
    }
}

?>
