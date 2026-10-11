<?php

declare(strict_types=1);

namespace Opmin\Module\Common\Internal;

/**
 * «Did you mean …?» for a mistyped name: the closest candidates by Levenshtein distance, case-insensitive.
 *
 * A candidate is close when the distance is at most a third of the name's length (at least 1): `sed`
 * → `seed`, but not `foo` → `seed`.
 *
 * @internal
 */
final class DidYouMean
{
    /**
     * The closest candidates (the nearest distance only), in the order given.
     *
     * @param iterable<string> $candidates
     * @param positive-int $limit
     * @return list<string>
     */
    public static function closest(string $name, iterable $candidates, int $limit = 3): array
    {
        $name = \strtolower($name);
        $threshold = \max(1, \intdiv(\strlen($name), 3));
        $best = [];
        $bestDistance = $threshold + 1;
        foreach ($candidates as $candidate) {
            $distance = \levenshtein($name, \strtolower($candidate));
            if ($distance > $threshold || $distance > $bestDistance) {
                continue;
            }

            if ($distance < $bestDistance) {
                $best = [];
                $bestDistance = $distance;
            }

            \in_array($candidate, $best, true) or $best[] = $candidate;
        }

        return \array_slice($best, 0, $limit);
    }

    /**
     * ` did you mean `a`?`, ` did you mean `a` or `b`?`; an empty string without suggestions.
     *
     * @param list<string> $suggestions
     */
    public static function phrase(array $suggestions): string
    {
        if ($suggestions === []) {
            return '';
        }

        $quoted = \array_map(static fn(string $s): string => "`{$s}`", $suggestions);
        $last = \array_pop($quoted);

        return ' Did you mean ' . ($quoted === [] ? $last : \implode(', ', $quoted) . ' or ' . $last) . '?';
    }
}
