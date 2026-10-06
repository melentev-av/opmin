<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

/**
 * Magic properties: isset($bag->x) calls __isset(), $bag->x !== null calls __get().
 */
final class MagicBag
{
    public int $reads = 0;

    /** @var array<string, mixed> */
    private array $data = [];

    public function __get(string $name): mixed
    {
        $this->reads++;

        return $this->data[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->data);
    }

    public function has(string $name): bool
    {
        return isset($this->{$name});
    }
}
