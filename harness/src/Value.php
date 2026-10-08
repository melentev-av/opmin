<?php

declare(strict_types=1);

namespace Opmin\Harness;

/**
 * Scalars in the protocol: floats as strings (to keep `-0.0`, `NAN`, `INF` and the exact value),
 * binary strings as base64 (JSON carries only UTF-8).
 *
 * @internal
 */
final class Value
{
    public static function floatToString(float $value): string
    {
        if (\is_nan($value)) {
            return 'NAN';
        }

        if (\is_infinite($value)) {
            return $value > 0 ? 'INF' : '-INF';
        }

        if ($value === 0.0) {
            return \fdiv(1.0, $value) < 0 ? '-0.0' : '0.0';
        }

        # Shortest representation that reads back to the same float, independent of the project's ini.
        $precision = \ini_set('serialize_precision', '-1');
        try {
            $text = \var_export($value, true);
        } finally {
            $precision === false or \ini_set('serialize_precision', $precision);
        }

        return $text;
    }

    public static function floatFromString(string $text): float
    {
        # `match`, not `switch`: '0.0' == '-0.0' for a loose comparison of numeric strings.
        return match ($text) {
            'NAN' => \NAN,
            'INF' => \INF,
            '-INF' => -\INF,
            '-0.0' => -0.0,
            default => \is_numeric($text) ? (float) $text : throw new \InvalidArgumentException("Not a float: {$text}"),
        };
    }

    /**
     * @return array{type: 'string', value?: string, base64?: string}
     */
    public static function string(string $value): array
    {
        return \preg_match('//u', $value) === 1
            ? ['type' => 'string', 'value' => $value]
            : ['type' => 'string', 'base64' => \base64_encode($value)];
    }

    /**
     * @param array<array-key, mixed> $recipe
     */
    public static function stringFrom(array $recipe): string
    {
        if (isset($recipe['base64'])) {
            $decoded = \base64_decode((string) $recipe['base64'], true);
            $decoded === false and throw new \InvalidArgumentException('Invalid base64 string');
            return $decoded;
        }

        return (string) ($recipe['value'] ?? '');
    }

    public static function errorLevel(int $level): string
    {
        $names = [
            \E_ERROR => 'E_ERROR', \E_WARNING => 'E_WARNING', \E_PARSE => 'E_PARSE', \E_NOTICE => 'E_NOTICE',
            \E_CORE_ERROR => 'E_CORE_ERROR', \E_CORE_WARNING => 'E_CORE_WARNING', \E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            \E_COMPILE_WARNING => 'E_COMPILE_WARNING', \E_USER_ERROR => 'E_USER_ERROR', \E_USER_WARNING => 'E_USER_WARNING',
            \E_USER_NOTICE => 'E_USER_NOTICE', 2048 => 'E_STRICT', \E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            \E_DEPRECATED => 'E_DEPRECATED', \E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        ];

        return $names[$level] ?? (string) $level;
    }
}
