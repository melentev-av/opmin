<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Property;

/**
 * Seeded randomness for input generators: the same seed gives the same inputs.
 *
 * @internal
 */
interface RandomSource
{
    /**
     * Uniform integer in [$min, $max].
     */
    public function int(int $min, int $max): int;

    /**
     * Uniform float in [0.0, 1.0).
     */
    public function float(): float;

    /**
     * Random bytes.
     *
     * @param int<0, max> $length
     */
    public function bytes(int $length): string;
}
