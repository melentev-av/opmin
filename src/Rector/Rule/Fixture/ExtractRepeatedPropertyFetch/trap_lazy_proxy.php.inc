<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

class Entity11
{
    protected readonly string $name;

    public function greet(): string
    {
        return $this->name . ', ' . $this->name;
    }
}

final class Entity11Proxy extends Entity11
{
    public function __construct()
    {
        unset($this->name);
    }

    public function __get(string $property): string
    {
        return 'loaded';
    }
}

?>
