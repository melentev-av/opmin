<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Hooked25
{
    private int $reads = 0;

    private int $value {
        get => ++$this->reads;
    }

    public function twice(): int
    {
        return $this->value + $this->value;
    }
}

final class HookedReadonly26
{
    public function __construct(private readonly int $base) {}

    private int $doubled {
        get => $this->base * 2;
    }

    public function twice(): int
    {
        return $this->doubled + $this->doubled;
    }
}

?>
