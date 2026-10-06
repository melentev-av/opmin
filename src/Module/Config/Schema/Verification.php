<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;

/**
 * Behavior verification: differential testing and its thresholds.
 *
 * @internal
 */
#[InflectableConfig]
final class Verification
{
    #[ConfigKey('verification.warnings', 'strict — any difference in warnings rolls back; allow_removal — removing a warning is allowed')]
    public WarningsPolicy $warnings = WarningsPolicy::Strict;

    /** @var int<0, 100> */
    #[ConfigKey('verification.min_branch_coverage', 'Branch coverage (%) of the original function required from differential tests')]
    public int $minBranchCoverage = 90;

    #[ConfigKey('verification.coverage_driver', 'Coverage driver: auto | xdebug | pcov')]
    public CoverageDriver $coverageDriver = CoverageDriver::Auto;

    /** @var non-negative-int */
    #[ConfigKey('verification.random_inputs', 'Number of random inputs per function')]
    public int $randomInputs = 200;

    /** @var non-negative-int */
    #[ConfigKey('verification.fuzz_time_ms', 'Time limit of coverage-guided input search per function, ms')]
    public int $fuzzTimeMs = 2000;

    #[ConfigKey('verification.seed', 'Seed of input generation')]
    public int $seed = 42;

    #[ConfigKey('verification.float_tolerance', 'Relative float tolerance; 0 — bitwise comparison')]
    public float $floatTolerance = 1.0e-12;

    /** @var positive-int */
    #[ConfigKey('verification.call_timeout_ms', 'Timeout of a single call in the harness, ms')]
    public int $callTimeoutMs = 1000;

    /** @var non-empty-string */
    #[ConfigKey('verification.memory_limit', 'memory_limit of harness workers')]
    public string $memoryLimit = '256M';

    #[ConfigKey('verification.allow_unverified', 'Change functions whose behavior is not proven')]
    public bool $allowUnverified = false;
}
