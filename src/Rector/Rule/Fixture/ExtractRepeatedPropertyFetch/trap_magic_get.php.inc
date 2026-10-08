<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Magic9
{
    public function __construct(private readonly int $value) {}

    public function __get(string $name): int
    {
        return random_int(0, 10);
    }

    public function twice(): int
    {
        return $this->value + $this->value;
    }
}

?>
