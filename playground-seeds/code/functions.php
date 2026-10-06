<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

function clamp(int $value, int $min, int $max): int
{
    if ($value < $min) {
        return $min;
    } else {
        if ($value > $max) {
            return $max;
        }
    }

    return $value;
}

function is_blank(?string $s): bool
{
    return $s === null || strlen(trim($s)) === 0;
}
