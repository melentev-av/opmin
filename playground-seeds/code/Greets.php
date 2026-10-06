<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

trait Greets
{
    public function greet(string $name): string
    {
        return 'Hello, ' . ucfirst(strtolower($name)) . '!';
    }
}
