<?php

declare(strict_types=1);

namespace Opmin\Module\Config;

use Opmin\Module\Config\Exception\ConfigException;

/**
 * Strict conversion of config values to the type of their key.
 *
 * Values from the YAML file keep their native types and must match exactly (an int is accepted
 * for a float key). Values from the environment and the CLI are strings and are parsed; anything
 * that does not parse cleanly is an error rather than a silent `0`/`false`.
 *
 * @internal
 */
final class ValueCaster
{
    /**
     * @param string $source Where the value came from, for the error message.
     * @throws ConfigException
     */
    public static function fromNative(KeyInfo $key, mixed $value, string $source): mixed
    {
        if ($value === null) {
            return $key->nullable ? null : throw self::error($key, $value, $source);
        }

        $enum = $key->enum();
        if ($enum !== null) {
            return (\is_string($value) || \is_int($value) ? $enum::tryFrom($value) : null)
                ?? throw self::error($key, $value, $source);
        }

        return match ($key->type) {
            'string' => \is_string($value) && $value !== '' ? $value : throw self::error($key, $value, $source),
            'int' => \is_int($value) ? $value : throw self::error($key, $value, $source),
            'float' => \is_int($value) || \is_float($value) ? (float) $value : throw self::error($key, $value, $source),
            'bool' => \is_bool($value) ? $value : throw self::error($key, $value, $source),
            'array' => self::array($key, $value, $source),
            default => throw new \LogicException("Unsupported config type `{$key->type}` of `{$key->key->path}`."),
        };
    }

    /**
     * @param string $source Where the value came from, for the error message.
     * @throws ConfigException
     */
    public static function fromString(KeyInfo $key, string $value, string $source): mixed
    {
        $trimmed = \trim($value);
        if ($key->nullable && ($trimmed === '' || \strtolower($trimmed) === 'null')) {
            return null;
        }

        $parsed = match ($key->type) {
            'int' => \filter_var($trimmed, \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE),
            'float' => \filter_var($trimmed, \FILTER_VALIDATE_FLOAT, \FILTER_NULL_ON_FAILURE),
            'bool' => \filter_var($trimmed, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE),
            'array' => self::parseArray($trimmed),
            default => $trimmed,
        };

        return $parsed === null
            ? throw self::error($key, $value, $source)
            : self::fromNative($key, $parsed, $source);
    }

    private static function array(KeyInfo $key, mixed $value, string $source): array
    {
        \is_array($value) or throw self::error($key, $value, $source);

        if ($key->key->list) {
            \array_is_list($value) or throw self::error($key, $value, $source);
            foreach ($value as $item) {
                \is_string($item) && $item !== '' or throw self::error($key, $value, $source);
            }

            return $value;
        }

        ($value === [] || !\array_is_list($value)) or throw self::error($key, $value, $source);

        return $value;
    }

    /**
     * JSON for maps and lists (`["a","b"]`, `{"k":false}`), otherwise a comma-separated list.
     *
     * @return array<array-key, mixed>|null
     */
    private static function parseArray(string $value): ?array
    {
        if (\str_starts_with($value, '[') || \str_starts_with($value, '{')) {
            try {
                $decoded = \json_decode($value, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }

            return \is_array($decoded) ? $decoded : null;
        }

        return $value === '' ? [] : \array_values(\array_filter(
            \array_map('trim', \explode(',', $value)),
            static fn(string $item): bool => $item !== '',
        ));
    }

    private static function error(KeyInfo $key, mixed $value, string $source): ConfigException
    {
        return ConfigException::invalidValue($key->key->path, $key->expected(), $value, $source);
    }
}
