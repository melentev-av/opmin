<?php

namespace App\InArray23;

final class Magic
{
    public function __construct(private readonly string $state) {}

    public function __get(string $name): string
    {
        return 'a';
    }

    public function open(): bool
    {
        return $this->state === 'a' || $this->state === 'b';
    }
}

?>
