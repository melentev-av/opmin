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
    #[ConfigKey('rector.custom_rules', 'opmin rules: FQCN => options, or false to disable a rule (the defaults disable some)')]
    public array $customRules = [
        'Opmin\Rector\Rule\FullyQualifyGlobalCallsRector' => [],
        'Opmin\Rector\Rule\ExtractRepeatedPropertyFetchRector' => ['min_reads' => 'auto'],
        'Opmin\Rector\Rule\ExtractRepeatedArrayDimFetchRector' => ['min_reads' => 'auto'],
        'Opmin\Rector\Rule\HoistLoopInvariantCountRector' => [],
        'Opmin\Rector\Rule\RemoveUnusedClosureUseRector' => [],
        'Opmin\Rector\Rule\SprintfToConcatRector' => [],
        'Opmin\Rector\Rule\ConcatToInterpolationRector' => [],
        'Opmin\Rector\Rule\ArrayPushToAssignRector' => [],
        'Opmin\Rector\Rule\StrStartsWithRector' => [],
        'Opmin\Rector\Rule\OrIdenticalToInArrayRector' => ['min_items' => 2],
        # Off by default: no cheaper by execution cost (callgrind, PHP 8.1–8.5): `?->` +7…+67 % with the
        # tracing JIT, `switch` → `match` +10…+16 % without JIT. Set options instead of `false` to enable.
        'Opmin\Rector\Rule\NullCheckTernaryToNullsafeRector' => false,
        # Off by default: cheaper (−24…−29 % without JIT, −14…−16 % with it), but `if` reads worse than
        # `match (true)`. Before IfChainToMatch, so that an `if` chain of string labels becomes `match ($v)`.
        'Opmin\Rector\Rule\MatchTrueToIfRector' => false,
        'Opmin\Rector\Rule\IfChainToMatchRector' => ['min_arms' => 'auto'],
        'Opmin\Rector\Rule\SwitchToMatchRector' => false,
    ];

    /** @var positive-int */
    #[ConfigKey('rector.max_passes', 'Maximum number of passes over the rule list')]
    public int $maxPasses = 3;
}
