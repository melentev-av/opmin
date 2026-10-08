<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

final class LazyEntityProxy extends LazyEntity
{
    private int $loads = 0;

    public function __construct()
    {
        unset($this->name);
    }

    public function __get(string $property): string
    {
        return 'loaded' . ++$this->loads;
    }
}
