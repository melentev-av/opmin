<?php

// Fixture for opcode counting (PHP 8.4+): property hooks, including a hook of a promoted property.

declare(strict_types=1);

namespace Fixture\Count;

final class Person
{
    public string $name {
        set(string $value) {
            $this->name = \trim($value);
        }
        get => \strtoupper($this->name);
    }

    public function __construct(
        public int $age {
            get {
                return $this->age * 2;
            }
        },
    ) {}
}
