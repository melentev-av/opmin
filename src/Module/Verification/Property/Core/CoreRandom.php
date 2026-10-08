<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Property\Core;

use Opmin\Module\Verification\Property\RandomSource;
use Rasuvaeff\PropertyTesting\Random;

/**
 * {@see RandomSource} over the engine's seeded `Random`.
 *
 * @internal
 */
final readonly class CoreRandom implements RandomSource
{
    public function __construct(
        private Random $random,
    ) {}

    public function int(int $min, int $max): int
    {
        return $this->random->int($min, $max);
    }

    public function float(): float
    {
        return $this->random->float();
    }

    public function bytes(int $length): string
    {
        return $this->random->bytes($length);
    }
}
