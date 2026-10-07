<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;
use Opmin\Module\Common\Internal\Attribute\InputOption;

/**
 * CI guard: `opmin check` against the baseline.
 *
 * @internal
 */
#[InflectableConfig]
final class Check
{
    /** @var non-negative-int */
    #[ConfigKey('check.tolerance', 'By how many opcodes a function may grow')]
    public int $tolerance = 0;

    /** @var positive-int|null */
    #[ConfigKey('check.max_ops_new_function', 'Opcode limit for new functions; null — no limit')]
    public ?int $maxOpsNewFunction = null;

    /** @var non-empty-string */
    #[ConfigKey('check.base_ref', 'Base ref for "changed files only" mode')]
    #[InputOption('base')]
    public string $baseRef = 'origin/main';
}
