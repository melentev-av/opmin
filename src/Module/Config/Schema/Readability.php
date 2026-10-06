<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;

/**
 * Thresholds that reject changes not worth their readability cost.
 *
 * @internal
 */
#[InflectableConfig]
final class Readability
{
    /** @var non-negative-int */
    #[ConfigKey('readability.min_gain', 'Minimal opcode gain to accept a change')]
    public int $minGain = 1;

    #[ConfigKey('readability.min_gain_per_line', 'Minimal opcode gain per changed line')]
    public float $minGainPerLine = 0.5;

    /** @var non-negative-int */
    #[ConfigKey('readability.max_cyclomatic_increase', 'Allowed growth of cyclomatic complexity')]
    public int $maxCyclomaticIncrease = 0;

    /** @var non-negative-int */
    #[ConfigKey('readability.max_nesting_increase', 'Allowed growth of nesting depth')]
    public int $maxNestingIncrease = 0;

    /** @var list<non-empty-string> */
    #[ConfigKey('readability.forbid_patterns', 'Constructs the LLM stage must not introduce', list: true)]
    public array $forbidPatterns = ['nested_ternary', 'assignment_in_condition', 'goto'];
}
