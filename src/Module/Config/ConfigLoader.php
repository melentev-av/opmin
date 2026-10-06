<?php

declare(strict_types=1);

namespace Opmin\Module\Config;

use Opmin\Module\Config\Exception\ConfigException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Finds, parses and validates `opmin.yaml`.
 *
 * Validation is strict and happens before anything runs: an unknown key or a value of the wrong
 * type is an error naming the key path, not a silently ignored setting.
 *
 * @internal
 */
final class ConfigLoader
{
    /** @var list<non-empty-string> Config file names, in lookup order. */
    public const FILES = ['opmin.yaml', 'opmin.yaml.dist'];

    /**
     * Resolves the config file: the explicit `--config` path, otherwise the first of
     * {@see self::FILES} found in the directory.
     *
     * @return non-empty-string|null
     * @throws ConfigException When the explicit file does not exist.
     */
    public static function locate(string $directory, ?string $explicit = null): ?string
    {
        if ($explicit !== null && $explicit !== '') {
            return \is_file($explicit)
                ? $explicit
                : throw new ConfigException("Config file not found: {$explicit}");
        }

        foreach (self::FILES as $name) {
            $file = \rtrim($directory, '/\\') . \DIRECTORY_SEPARATOR . $name;
            if (\is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return array<non-empty-string, mixed> Typed values by key path.
     * @throws ConfigException
     */
    public static function loadFile(string $file): array
    {
        $content = @\file_get_contents($file);
        $content === false and throw new ConfigException("Failed to read config file: {$file}");

        return self::loadString($content, \basename($file));
    }

    /**
     * @param string $source File name for error messages.
     * @return array<non-empty-string, mixed> Typed values by key path.
     * @throws ConfigException
     */
    public static function loadString(string $yaml, string $source = 'opmin.yaml'): array
    {
        try {
            /** @var mixed $data */
            $data = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            throw new ConfigException("Invalid YAML in {$source}: {$e->getMessage()}", previous: $e);
        }

        if ($data === null) {
            return [];
        }

        \is_array($data) && ($data === [] || !\array_is_list($data)) or throw new ConfigException(
            "{$source} must contain a map of config keys.",
        );

        $values = [];
        self::collect($data, '', $source, $values);

        return $values;
    }

    /**
     * Parses `--set=key=value` overrides.
     *
     * @param list<string> $overrides
     * @return array<non-empty-string, mixed> Typed values by key path.
     * @throws ConfigException
     */
    public static function parseOverrides(array $overrides): array
    {
        $values = [];
        foreach ($overrides as $override) {
            $pos = \strpos($override, '=');
            $pos === false || $pos === 0 and throw new ConfigException(
                "Invalid --set value `{$override}`, expected key=value.",
            );

            $path = \substr($override, 0, $pos);
            $key = ConfigSchema::find($path) ?? throw ConfigException::unknownKey($path, '--set');
            $values[$key->key->path] = ValueCaster::fromString($key, \substr($override, $pos + 1), '--set');
        }

        return $values;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param array<non-empty-string, mixed> $values
     */
    private static function collect(array $node, string $prefix, string $source, array &$values): void
    {
        foreach ($node as $name => $value) {
            $path = $prefix . $name;
            $key = ConfigSchema::find($path);

            if ($key !== null) {
                $values[$key->key->path] = ValueCaster::fromNative($key, $value, $source);
                continue;
            }

            if (\is_string($name) && \is_array($value) && ConfigSchema::isSection($path)) {
                self::collect($value, $path . '.', $source, $values);
                continue;
            }

            throw ConfigException::unknownKey($path, $source);
        }
    }
}
