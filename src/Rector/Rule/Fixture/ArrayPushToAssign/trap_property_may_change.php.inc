<?php

namespace App\ArrayPush12;

final class PublicList
{
    public array $items = [];

    public function add(int $v): void
    {
        \array_push($this->items, $v);
    }
}

final class Unsets
{
    private array $items = [];

    public function reset(): void
    {
        unset($this->items);
    }

    public function add(int $v): void
    {
        \array_push($this->items, $v);
    }
}

class OpenProtected
{
    protected array $items = [];

    public function add(int $v): void
    {
        \array_push($this->items, $v);
    }
}

final class Frozen
{
    public function __construct(private readonly array $items = []) {}

    public function add(int $v): void
    {
        \array_push($this->items, $v);
    }
}

class LateStatic
{
    protected static array $cache = [];

    public function add(int $v): void
    {
        \array_push(static::$cache, $v);
    }
}

final class Nested
{
    private array $items = ['k' => []];

    public function add(int $v): void
    {
        \array_push($this->items['k'], $v);
    }
}

?>
