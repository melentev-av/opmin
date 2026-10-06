<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;

/**
 * Rolls back changes that make code measurably slower.
 *
 * @internal
 */
#[InflectableConfig]
final class GuardPerf
{
    #[ConfigKey('guard_perf.enabled', 'Measure time and roll back slower code')]
    public bool $enabled = false;

    /** @var non-negative-int */
    #[ConfigKey('guard_perf.max_regression_percent', 'Allowed slowdown, %')]
    public int $maxRegressionPercent = 5;
}
