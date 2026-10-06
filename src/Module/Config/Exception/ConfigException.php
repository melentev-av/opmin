<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Exception;

/**
 * Invalid configuration: unknown key, wrong value type, unreadable or malformed file.
 *
 * The message always names the key path (and the source of the value when it is not the file),
 * so the user can find the mistake without guessing.
 *
 * @internal
 */
final class ConfigException extends \RuntimeException
{
    public static function unknownKey(string $path, string $source): self
    {
        return new self(\sprintf('Unknown config key `%s` (%s).', $path, $source));
    }

    public static function invalidValue(string $path, string $expected, mixed $value, string $source): self
    {
        return new self(\sprintf(
            'Config key `%s` expects %s, got %s (%s).',
            $path,
            $expected,
            self::describe($value),
            $source,
        ));
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            \is_string($value) => 'string "' . $value . '"',
            \is_bool($value) => 'bool ' . ($value ? 'true' : 'false'),
            \is_int($value), \is_float($value) => \get_debug_type($value) . ' ' . \var_export($value, true),
            default => \get_debug_type($value),
        };
    }
}
