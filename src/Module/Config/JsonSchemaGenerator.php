<?php

declare(strict_types=1);

namespace Opmin\Module\Config;

use Opmin\Info;

/**
 * Generates the JSON Schema of `opmin.yaml` from the config DTOs.
 *
 * IDEs pick it up through the `# yaml-language-server: $schema=...` line `opmin init` writes,
 * which gives completion and validation while editing the file.
 *
 * @internal
 */
final class JsonSchemaGenerator
{
    /**
     * @return array<string, mixed>
     */
    public static function generate(): array
    {
        $root = [
            '$schema' => 'https://json-schema.org/draft-07/schema#',
            '$id' => Info::SCHEMA_URL,
            'title' => 'opmin configuration',
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [],
        ];

        foreach (ConfigSchema::keys() as $path => $key) {
            $node = &$root;
            $segments = \explode('.', $path);
            $leaf = \array_pop($segments);
            foreach ($segments as $segment) {
                $node['properties'][$segment] ??= [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [],
                ];
                $node = &$node['properties'][$segment];
            }

            $node['properties'][$leaf] = self::property($key);
            unset($node);
        }

        return $root;
    }

    public static function toJson(): string
    {
        return \json_encode(
            self::generate(),
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION,
        ) . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    private static function property(KeyInfo $key): array
    {
        $enum = $key->enum();
        $schema = match (true) {
            $enum !== null => ['enum' => \array_map(static fn(\BackedEnum $case): string|int => $case->value, $enum::cases())],
            $key->type === 'array' && $key->key->list => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1]],
            $key->type === 'array' => ['type' => 'object'],
            $key->type === 'string' => ['type' => 'string', 'minLength' => 1],
            default => ['type' => match ($key->type) {
                'int' => 'integer',
                'float' => 'number',
                'bool' => 'boolean',
                default => throw new \LogicException("Unsupported config type `{$key->type}`."),
            }],
        };

        if ($key->nullable) {
            $schema = isset($schema['enum'])
                ? ['enum' => [...$schema['enum'], null]]
                : ['anyOf' => [$schema, ['type' => 'null']]];
        }

        $default = $key->default instanceof \BackedEnum ? $key->default->value : $key->default;

        return ['description' => $key->key->description] + $schema + ['default' => $default];
    }
}
