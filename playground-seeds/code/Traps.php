<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

/**
 * Functions that look optimizable but are not: every "obvious" rewrite here changes behavior.
 */
final class Traps
{
    /**
     * $upper looks unused, but compact() reads it by name.
     *
     * @return array<string, mixed>
     */
    public function context(string $user, int $id): array
    {
        $upper = strtoupper($user);

        return compact('user', 'id', 'upper');
    }

    /** State between calls: the second call differs from the first. */
    public function counter(): int
    {
        static $calls = 0;

        return ++$calls;
    }

    /** Depends on the actual arguments passed, not on the signature. */
    public function argCount(int $a, int $b = 0): int
    {
        return func_num_args() * 10 + count(func_get_args());
    }

    /**
     * Values may be null: array_key_exists() → isset() changes the result.
     *
     * @param array<string, string|null> $map
     */
    public function hasKey(array $map, string $key): bool
    {
        return array_key_exists($key, $map);
    }

    /** Tests mock time() through ClockMock: \time() would bypass the mock. */
    public function isExpired(int $deadline): bool
    {
        return time() > $deadline;
    }

    /** A branch reachable only with a magic string from the body. */
    public function mode(string $mode): string
    {
        if ($mode === 'legacy-v1-compat') {
            return 'legacy';
        }

        return 'modern';
    }

    /** Loose comparison: == → === changes the result for '0', 0.0, '' and null. */
    public function isZero(mixed $x): bool
    {
        return $x == 0;
    }
}
