<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize\Rector;

/**
 * What a rule saves, and so how its changes are accepted (brief, «Важное замечание по метрике»).
 *
 * A rule declares it with a public constant: `EXECUTED_GAIN = true` or `COST_GAIN = true`.
 *
 * @internal
 */
enum Gain: string
{
    /**
     * Static opcodes (`ops_opt`): accepted from `readability.min_gain`.
     */
    case Opcodes = 'opcodes';

    /**
     * Executed opcodes (an invariant out of a loop): accepted when the static count does not grow.
     */
    case Executed = 'executed';

    /**
     * Execution cost, measured for the rule by `bin/bench --cost` (`docs/rule-cost.md`): accepted
     * even when the static count grows, the count stays in the report for reference.
     */
    case Cost = 'cost';

    /**
     * @param class-string $class A rule.
     */
    public static function of(string $class): self
    {
        return match (true) {
            self::flag($class, 'COST_GAIN') => self::Cost,
            self::flag($class, 'EXECUTED_GAIN') => self::Executed,
            default => self::Opcodes,
        };
    }

    /**
     * "-4 opcodes", "fewer executed opcodes", "+1 opcodes, cheaper to run".
     *
     * @param int $gain Opcodes saved (negative: grown).
     */
    public function describe(int $gain, string $minus = '-'): string
    {
        return match (true) {
            $this === self::Executed && $gain === 0 => 'fewer executed opcodes',
            $this === self::Cost && $gain === 0 => 'cheaper to run',
            $this === self::Cost && $gain < 0 => \sprintf('+%d opcodes, cheaper to run', -$gain),
            default => "{$minus}{$gain} opcodes",
        };
    }

    private static function flag(string $class, string $name): bool
    {
        return \defined("{$class}::{$name}") && \constant("{$class}::{$name}") === true;
    }
}
