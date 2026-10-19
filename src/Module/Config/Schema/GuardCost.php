<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;

/**
 * Rolls back changes that cost more CPU instructions per call, measured under callgrind without the
 * JIT and with the tracing JIT on the inputs of the differential test (Linux, or a valgrind wrapper
 * into Docker).
 *
 * @internal
 */
#[InflectableConfig]
final class GuardCost
{
    #[ConfigKey('guard_cost.enabled', 'Measure instructions per call under callgrind and roll back costlier code')]
    public bool $enabled = false;

    /** @var non-negative-int */
    #[ConfigKey('guard_cost.max_regression_percent', 'Allowed growth of instructions per call, %; a change is rolled back when it exceeds this and max_regression_instructions')]
    public int $maxRegressionPercent = 5;

    /** @var non-negative-int */
    #[ConfigKey('guard_cost.max_regression_instructions', 'Allowed growth of instructions per call; see max_regression_percent')]
    public int $maxRegressionInstructions = 15;

    /** @var positive-int */
    #[ConfigKey('guard_cost.inputs', 'Inputs of the differential test measured per function')]
    public int $inputs = 8;

    /** @var non-empty-string */
    #[ConfigKey('guard_cost.valgrind', 'valgrind executable, or a wrapper that runs it where php.binary runs (Docker)')]
    public string $valgrind = 'valgrind';

    /** @var non-empty-string|null */
    #[ConfigKey('guard_cost.php', 'PHP executable valgrind starts; null — php.binary (set `php` when guard_cost.valgrind runs in a container)')]
    public ?string $php = null;
}
