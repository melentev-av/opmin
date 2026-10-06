<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Compare;

use Opmin\Module\Config\Schema\WarningsPolicy;

/**
 * How strictly two call results are compared.
 *
 * @internal
 */
final readonly class ComparisonPolicy
{
    /**
     * @param float $floatTolerance Relative tolerance of floats; 0 — bitwise.
     */
    public function __construct(
        public WarningsPolicy $warnings = WarningsPolicy::Strict,
        public float $floatTolerance = 1.0e-12,
    ) {}

    /**
     * For the determinism check: the original against itself, nothing is tolerated but the float error.
     */
    public function strict(): self
    {
        return new self(WarningsPolicy::Strict, $this->floatTolerance);
    }
}
