<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;

/**
 * Stage A: standard Rector rules. The list always stays in the config; the whole group is
 * switched on and off by the single `enabled` line.
 *
 * @internal
 */
#[InflectableConfig]
final class RectorStandard
{
    #[ConfigKey('rector.standard.enabled', 'The only line to change to enable the standard Rector rules')]
    public bool $enabled = false;

    /** @var list<non-empty-string> */
    #[ConfigKey('rector.standard.sets', 'Rector sets, expanded into single rules (UP_TO_PHP_TARGET follows php.target)', list: true)]
    # EARLY_RETURN is empty in Rector 2.7: its rules moved to CODE_QUALITY (docs/standard-rules.md).
    public array $sets = ['DEAD_CODE', 'CODE_QUALITY', 'UP_TO_PHP_TARGET'];

    /** @var list<non-empty-string> */
    #[ConfigKey('rector.standard.rules', 'Single standard rules (FQCN)', list: true)]
    # Rules that save opcodes, measured by `bin/bench --only=standard` (docs/standard-rules.md).
    public array $rules = [
        'Rector\CodeQuality\Rector\FuncCall\SingleInArrayToCompareRector',
        'Rector\CodeQuality\Rector\FuncCall\SimplifyFuncGetArgsCountRector',
        'Rector\CodeQuality\Rector\Identical\StrlenZeroToIdenticalEmptyStringRector',
        'Rector\CodeQuality\Rector\Ternary\UnnecessaryTernaryExpressionRector',
        'Rector\DeadCode\Rector\Assign\RemoveUnusedVariableAssignRector',
        'Rector\DeadCode\Rector\Return_\RemoveDeadConditionAboveReturnRector',
    ];

    /** @var list<non-empty-string> */
    #[ConfigKey('rector.standard.skip', 'Rules to exclude from the sets above (FQCN)', list: true)]
    public array $skip = [];
}
