<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

/**
 * Traps for opmin's own rules: each method is what a rule must leave alone (or the verifier must
 * reject). The weak tests in WeakRuleTrapsTest pass on wrong rewrites too.
 */
final class RuleTraps
{
    /**
     * A missing key warns on every read: two warnings, after extraction one.
     *
     * @param array<string, int> $a
     * @return list<int>
     */
    public function pair(array $a): array
    {
        return [$a['k'], $a['k']];
    }

    public function offsets(CountingOffsets $o): int
    {
        return $o['k'] + $o['k'];
    }

    /**
     * The loop appends to the array: its count changes on the way.
     *
     * @param list<int> $a
     */
    public function grow(array $a): int
    {
        $k = 0;
        for ($i = 0; $i < \count($a); $i++) {
            $k++;
            if ($a[$i] === 1) {
                $a[] = 2;
            }
        }

        return $k;
    }

    public function shrink(ShrinkingCountable $c): int
    {
        $k = 0;
        for ($i = 0; $i < \count($c); $i++) {
            $k++;
        }

        return $k;
    }
}
