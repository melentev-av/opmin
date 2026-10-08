<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Compare;

use Opmin\Module\Config\Schema\WarningsPolicy;

/**
 * Compares two call results of the harness (`docs/harness-protocol.md`) by the rules of brief 2.1–2.5:
 * the return value strictly and objects structurally (with identity through the normalized ids),
 * array key order matters, floats within a relative tolerance but `NAN` equals only `NAN` and
 * `-0.0` is not `0.0`; exceptions by class, message, code and the `previous` chain; output; the
 * sequence of warnings by level and text (lines are not compared, `@`-silenced ones are ignored);
 * by-ref arguments, `$this`, the mock journal, changed globals and statics.
 *
 * Messages are normalized: `called in /path on line N` of a `TypeError` names the caller's line.
 *
 * @psalm-type Result = array<string, mixed>
 * @internal
 */
final readonly class ResultComparator
{
    public function __construct(
        private ComparisonPolicy $policy = new ComparisonPolicy(),
    ) {}

    /**
     * @param array<array-key, mixed> $original Response of `call` for the original version.
     * @param array<array-key, mixed> $changed Response of `call` for the changed version.
     * @return Difference|null Null when equivalent.
     */
    public function compare(array $original, array $changed): ?Difference
    {
        $status = $this->scalar('status', $original['status'] ?? null, $changed['status'] ?? null);
        if ($status !== null) {
            return $status;
        }

        /** @var list<array<array-key, mixed>> $calls */
        $calls = \is_array($original['calls'] ?? null) ? $original['calls'] : [];
        /** @var list<array<array-key, mixed>> $otherCalls */
        $otherCalls = \is_array($changed['calls'] ?? null) ? $changed['calls'] : [];
        if (\count($calls) !== \count($otherCalls)) {
            return new Difference('calls', \count($calls) . ' call(s)', \count($otherCalls) . ' call(s)');
        }

        foreach ($calls as $i => $call) {
            $difference = $this->call("calls[{$i}]", $call, $otherCalls[$i]);
            if ($difference !== null) {
                return $difference;
            }
        }

        foreach (['args', 'this', 'mocks', 'globals', 'statics'] as $key) {
            $difference = $this->value($key, $original[$key] ?? null, $changed[$key] ?? null);
            if ($difference !== null) {
                return $difference;
            }
        }

        return null;
    }

    /**
     * @return list<string> `E_WARNING: text`
     */
    private static function errorList(mixed $errors): array
    {
        $result = [];
        /** @var mixed $error */
        foreach (\is_array($errors) ? $errors : [] as $error) {
            if (!\is_array($error) || ($error['suppressed'] ?? false) === true) {
                continue;
            }

            $result[] = \sprintf('%s: %s', (string) ($error['level'] ?? '?'), self::message($error['message'] ?? ''));
        }

        return $result;
    }

    /**
     * @param list<string> $needle
     * @param list<string> $haystack
     */
    private static function isSubsequence(array $needle, array $haystack): bool
    {
        $i = 0;
        foreach ($haystack as $item) {
            isset($needle[$i]) && $needle[$i] === $item and ++$i;
        }

        return $i === \count($needle);
    }

    /**
     * @param array<array-key, mixed> $string
     */
    private static function bytes(array $string): string
    {
        return isset($string['base64']) ? (string) \base64_decode((string) $string['base64'], true) : (string) ($string['value'] ?? '');
    }

    /**
     * An exception description with normalized messages, recursively through `previous`.
     */
    private static function normalized(mixed $exception): mixed
    {
        if (!\is_array($exception)) {
            return $exception;
        }

        isset($exception['message']) and $exception['message'] = self::message($exception['message']);
        if (isset($exception['previous'])) {
            /** @psalm-suppress MixedAssignment */
            $exception['previous'] = self::normalized($exception['previous']);
        }

        return $exception;
    }

    private static function message(mixed $message): string
    {
        $message = \is_string($message) ? $message : (string) \json_encode($message);

        # `f(): Argument #1 ($x) must be of type int, string given, called in /app/src/A.php on line 12`
        return (string) \preg_replace('/, called in .+? on line \d+/', '', $message);
    }

    /**
     * @param list<string> $items
     */
    private static function list(array $items): string
    {
        return $items === [] ? 'none' : \implode('; ', $items);
    }

    private static function short(mixed $value): string
    {
        if (\is_array($value) && isset($value['type']) && \is_string($value['type'])) {
            /** @var array<array-key, mixed> $value */
            return match ($value['type']) {
                'null' => 'null',
                'bool' => ($value['value'] ?? false) === true ? 'true' : 'false',
                'int', 'float' => $value['type'] . ' ' . (string) \json_encode($value['value'] ?? null),
                'string' => 'string ' . self::excerpt(self::bytes($value)),
                'object', 'enum' => $value['type'] . ' ' . (string) ($value['class'] ?? '?') . (isset($value['case']) ? '::' . (string) $value['case'] : ''),
                'array' => 'array(' . self::count($value['items'] ?? null) . ')',
                default => $value['type'],
            };
        }

        return match (true) {
            \is_string($value) => self::excerpt($value),
            \is_scalar($value), $value === null => (string) \json_encode($value, \JSON_PRESERVE_ZERO_FRACTION),
            default => \substr((string) \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION), 0, 80),
        };
    }

    private static function count(mixed $items): int
    {
        return \is_array($items) ? \count($items) : 0;
    }

    private static function excerpt(string $text): string
    {
        $encoded = (string) \json_encode($text, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);

        return \strlen($encoded) > 80 ? \substr($encoded, 0, 77) . '…"' : $encoded;
    }

    /**
     * @param array<array-key, mixed> $original
     * @param array<array-key, mixed> $changed
     */
    private function call(string $path, array $original, array $changed): ?Difference
    {
        $difference = $this->scalar("{$path}.status", $original['status'] ?? null, $changed['status'] ?? null);
        if ($difference !== null) {
            return $difference;
        }

        $difference = match ($original['status'] ?? null) {
            'returned' => $this->value("{$path}.value", $original['value'] ?? null, $changed['value'] ?? null),
            'threw' => $this->value("{$path}.exception", self::normalized($original['exception'] ?? null), self::normalized($changed['exception'] ?? null)),
            default => $this->scalar("{$path}.message", self::message($original['message'] ?? null), self::message($changed['message'] ?? null)),
        };

        return $difference
            ?? $this->value("{$path}.output", $original['output'] ?? null, $changed['output'] ?? null)
            ?? $this->errors("{$path}.errors", $original['errors'] ?? [], $changed['errors'] ?? []);
    }

    /**
     * Warnings, notices and deprecations: the sequence of level and text, without `@`-silenced ones.
     */
    private function errors(string $path, mixed $original, mixed $changed): ?Difference
    {
        $before = self::errorList($original);
        $after = self::errorList($changed);
        if ($before === $after) {
            return null;
        }

        # allow_removal: the changed version may drop errors, never add or reorder them.
        if ($this->policy->warnings === WarningsPolicy::AllowRemoval && self::isSubsequence($after, $before)) {
            return null;
        }

        return new Difference($path, self::list($before), self::list($after));
    }

    /**
     * Structural equality of value descriptions (and of any JSON-like data around them).
     */
    private function value(string $path, mixed $original, mixed $changed): ?Difference
    {
        if (\is_array($original) && \is_array($changed) && \is_string($original['type'] ?? null) && $original['type'] === ($changed['type'] ?? null)) {
            /** @var array<array-key, mixed> $original */
            /** @var array<array-key, mixed> $changed */
            if ($original['type'] === 'float') {
                return $this->float($path, (string) ($original['value'] ?? ''), (string) ($changed['value'] ?? ''));
            }

            if ($original['type'] === 'string') {
                return self::bytes($original) === self::bytes($changed) ? null : new Difference($path, self::short($original), self::short($changed));
            }
        }

        if (!\is_array($original) || !\is_array($changed)) {
            return $original === $changed ? null : new Difference($path, self::short($original), self::short($changed));
        }

        if (\array_is_list($original) !== \array_is_list($changed) || \count($original) !== \count($changed)) {
            return new Difference($path, self::short($original), self::short($changed));
        }

        if (\array_is_list($original)) {
            /** @var mixed $item */
            foreach ($original as $i => $item) {
                $difference = $this->value("{$path}[{$i}]", $item, $changed[$i]);
                if ($difference !== null) {
                    return $difference;
                }
            }

            return null;
        }

        # Maps (a description, the globals): the same keys; the order of descriptions' fields is not data.
        /** @var mixed $item */
        foreach ($original as $key => $item) {
            if (!\array_key_exists($key, $changed)) {
                return new Difference("{$path}.{$key}", self::short($item), 'absent');
            }

            $difference = $this->value("{$path}.{$key}", $item, $changed[$key]);
            if ($difference !== null) {
                return $difference;
            }
        }

        return null;
    }

    /**
     * `NAN` equals only `NAN`, infinities by sign, `-0.0` is not `0.0`; otherwise a relative tolerance.
     */
    private function float(string $path, string $original, string $changed): ?Difference
    {
        if ($original === $changed) {
            return null;
        }

        $difference = new Difference($path, "float {$original}", "float {$changed}");
        $special = ['NAN', 'INF', '-INF', '-0.0', '0.0'];
        if ($this->policy->floatTolerance <= 0.0 || \in_array($original, $special, true) || \in_array($changed, $special, true)
            || !\is_numeric($original) || !\is_numeric($changed)
        ) {
            return $difference;
        }

        $a = (float) $original;
        $b = (float) $changed;
        # Different signs are never "close": the sign is visible (`1 / $x`, comparisons with 0).
        if (($a < 0) !== ($b < 0)) {
            return $difference;
        }

        return \abs($a - $b) <= $this->policy->floatTolerance * \max(\abs($a), \abs($b)) ? null : $difference;
    }

    private function scalar(string $path, mixed $original, mixed $changed): ?Difference
    {
        return $original === $changed ? null : new Difference($path, self::short($original), self::short($changed));
    }
}
