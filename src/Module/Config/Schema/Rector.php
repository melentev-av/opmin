<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;

/**
 * Stage A: opmin's own Rector rules and common settings.
 *
 * @internal
 */
#[InflectableConfig]
final class Rector
{
    /**
     * Rule FQCN => rule options, or `false` to disable the rule.
     *
     * @var array<non-empty-string, array<string, mixed>|false>
     */
    #[ConfigKey('rector.custom_rules', 'opmin rules (enabled by default): FQCN => options, or false to disable a rule')]
    public array $customRules = [
        'Opmin\Rector\Rule\FullyQualifyGlobalCallsRector' => [],
        'Opmin\Rector\Rule\ExtractRepeatedPropertyFetchRector' => ['min_reads' => 'auto'],
        'Opmin\Rector\Rule\ExtractRepeatedArrayDimFetchRector' => ['min_reads' => 'auto'],
        'Opmin\Rector\Rule\HoistLoopInvariantCountRector' => [],
    ];

    /** @var positive-int */
    #[ConfigKey('rector.max_passes', 'Maximum number of passes over the rule list')]
    public int $maxPasses = 3;
}
