<?php

declare(strict_types=1);

namespace Opmin\Module\Common\Internal\Injection;

use Internal\Container\Container;
use Internal\Container\Inflector;
use Opmin\Module\Common\Internal\Attribute\ConfigAttribute;
use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\Env;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;
use Opmin\Module\Common\Internal\Attribute\InputArgument;
use Opmin\Module\Common\Internal\Attribute\InputOption;
use Opmin\Module\Common\Internal\Attribute\PhpIni;
use Opmin\Module\Config\ConfigSchema;
use Opmin\Module\Config\Exception\ConfigException;
use Opmin\Module\Config\KeyInfo;
use Opmin\Module\Config\ValueCaster;

/**
 * Configuration loader inflector.
 *
 * Hydrates configuration objects with values from different sources based on their property
 * attributes. The first source that has a value wins, in a fixed order whatever the order of the
 * attributes: explicit CLI options and arguments, `--set` overrides, environment variables
 * ({@see Env} first, then the variable derived from {@see ConfigKey}), PHP ini, the config file.
 * So a CI job can override a committed `opmin.yaml` without editing it.
 *
 * File and `--set` values arrive already validated and typed by
 * {@see \Opmin\Module\Config\ConfigLoader}; environment and CLI strings are cast strictly here.
 *
 * @internal
 */
final class ConfigInflector implements Inflector
{
    /**
     * @param array<string, string> $env Environment variables.
     * @param array<string, mixed> $inputArguments CLI arguments.
     * @param array<string, mixed> $inputOptions CLI options.
     * @param array<non-empty-string, mixed> $values Typed values of the config file, by key path.
     * @param array<non-empty-string, mixed> $overrides Typed `--set` values, by key path.
     */
    public function __construct(
        private readonly array $env = [],
        private readonly array $inputArguments = [],
        private readonly array $inputOptions = [],
        private readonly array $values = [],
        private readonly array $overrides = [],
    ) {}

    /**
     * Hydrates a configuration object with values from the configured sources.
     *
     * @throws ConfigException When a value from the environment or the CLI has a wrong type.
     */
    #[\Override]
    public function inflect(object $object, Container $container): object
    {
        $reflection = new \ReflectionObject($object);
        if ($reflection->getAttributes(InflectableConfig::class) === []) {
            return $object;
        }

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(ConfigAttribute::class, \ReflectionAttribute::IS_INSTANCEOF);
            if ($attributes === []) {
                continue;
            }

            $this->injectValue($object, $property, \array_map(
                static fn(\ReflectionAttribute $attribute): ConfigAttribute => $attribute->newInstance(),
                $attributes,
            ));
        }

        return $object;
    }

    /**
     * @param list<ConfigAttribute> $attributes
     */
    private function injectValue(object $config, \ReflectionProperty $property, array $attributes): void
    {
        $key = null;
        foreach ($attributes as $attribute) {
            $attribute instanceof ConfigKey and $key = ConfigSchema::find($attribute->path);
        }

        foreach ($this->candidates($attributes) as [$value, $source]) {
            $property->setValue($config, $this->cast($property, $key, $value, $source));
            return;
        }
    }

    /**
     * Values present in the sources, highest priority first.
     *
     * @param list<ConfigAttribute> $attributes
     * @return iterable<array{mixed, non-empty-string}> Raw value and its source label.
     */
    private function candidates(array $attributes): iterable
    {
        $byPriority = [];
        foreach ($attributes as $attribute) {
            match (true) {
                $attribute instanceof InputOption => $byPriority[0][] = $this->present(
                    $this->inputOptions[$attribute->name] ?? null,
                    "option --{$attribute->name}",
                ),
                $attribute instanceof InputArgument => $byPriority[0][] = $this->present(
                    $this->inputArguments[$attribute->name] ?? null,
                    "argument {$attribute->name}",
                ),
                $attribute instanceof ConfigKey => [
                    $byPriority[1][] = \array_key_exists($attribute->path, $this->overrides)
                        ? [$this->overrides[$attribute->path], '--set']
                        : null,
                    $byPriority[3][] = $this->present(
                        $this->env[$attribute->envName()] ?? null,
                        "env {$attribute->envName()}",
                    ),
                    $byPriority[5][] = \array_key_exists($attribute->path, $this->values)
                        ? [$this->values[$attribute->path], 'config file']
                        : null,
                ],
                $attribute instanceof Env => $byPriority[2][] = $this->present(
                    $this->env[$attribute->name] ?? null,
                    "env {$attribute->name}",
                ),
                $attribute instanceof PhpIni => $byPriority[4][] = $this->present(
                    \in_array($ini = \ini_get($attribute->option), ['', false], true) ? null : $ini,
                    "ini {$attribute->option}",
                ),
                default => null,
            };
        }

        \ksort($byPriority);
        foreach ($byPriority as $candidates) {
            foreach ($candidates as $candidate) {
                $candidate === null or yield $candidate;
            }
        }
    }

    /**
     * A CLI/env value counts as present unless it is absent, null or an empty list
     * (an array option that was not passed).
     *
     * @param non-empty-string $source
     * @return array{mixed, non-empty-string}|null
     */
    private function present(mixed $value, string $source): ?array
    {
        return $value === null || $value === [] ? null : [$value, $source];
    }

    /**
     * @param non-empty-string $source
     */
    private function cast(\ReflectionProperty $property, ?KeyInfo $key, mixed $value, string $source): mixed
    {
        # File and --set values are already validated and typed
        if ($source === 'config file' || $source === '--set') {
            return $value;
        }

        if ($key !== null) {
            return \is_string($value)
                ? ValueCaster::fromString($key, $value, $source)
                : ValueCaster::fromNative($key, $value, $source);
        }

        # Plain CLI/env bindings without a config key
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin()) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => \filter_var($value, \FILTER_VALIDATE_BOOLEAN),
            'array' => \is_array($value) ? $value : \explode(',', (string) $value),
            default => $value,
        };
    }
}
