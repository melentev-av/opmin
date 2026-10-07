<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

final class BackRefHolder
{
    private ?BackRefNode $foo;

    public function __construct()
    {
        $this->foo = new BackRefNode();
        $this->foo->owner = $this;
    }

    public function reset(): void
    {
        $this->foo = null;
    }

    /**
     * @return array{int, ?int}
     */
    public function run(): array
    {
        $a = $this->foo->bar();
        $b = $this->foo?->bar();

        return [$a, $b];
    }
}
