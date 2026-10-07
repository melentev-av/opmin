<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Name24
{
    public function __toString(): string
    {
        return 'n';
    }
}

final class Holder24
{
    private Name24 $name;

    public function __construct()
    {
        $this->name = new Name24();
    }

    public function twice(): string
    {
        return $this->name . '-' . $this->name;
    }
}

?>
