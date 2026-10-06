<?php

declare(strict_types=1);

namespace Opmin\Harness;

/**
 * Branch probes of the instrumented original version: the orchestrator inserts
 * `\Opmin\Harness\Probe::hit(N)` at the start of every branch, on the same line, so the code keeps
 * its lines and its behavior.
 *
 * @internal
 */
final class Probe
{
    /** @var array<int, true> */
    public static array $hits = [];

    /**
     * Returns null so it can prefix an expression: `(\Opmin\Harness\Probe::hit(3) ?? $expr)`.
     */
    public static function hit(int $id): ?bool
    {
        self::$hits[$id] = true;

        return null;
    }

    public static function reset(): void
    {
        self::$hits = [];
    }

    /**
     * @return list<int>
     */
    public static function collect(): array
    {
        $hits = \array_keys(self::$hits);
        \sort($hits);

        return $hits;
    }
}
