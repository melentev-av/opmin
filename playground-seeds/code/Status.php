<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

enum Status: string
{
    case Active = 'active';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Blocked => 'Blocked',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Active], true);
    }
}
