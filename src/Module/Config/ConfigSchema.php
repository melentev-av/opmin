<?php

declare(strict_types=1);

namespace Opmin\Module\Config;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\DidYouMean;

/**
 * Registry of all `opmin.yaml` keys, built from the config DTOs.
 *
 * The DTOs are the single source of truth: validation of the file, type casting of overrides,
 * the JSON Schema and the file written by `opmin init` are all derived from them.
 *
 * @internal
 */
final class ConfigSchema
{
    /**
     * Config DTOs, in the order their sections appear in `opmin.yaml`.
     *
     * @var list<class-string>
     */
    public const SECTIONS = [
        Schema\Project::class,
        Schema\Php::class,
        Schema\Tests::class,
        Schema\Commands::class,
        Schema\Rector::class,
        Schema\RectorStandard::class,
        Schema\Verification::class,
        Schema\Readability::class,
        Schema\Signatures::class,
        Schema\Ignore::class,
        Schema\Llm::class,
        Schema\GuardPerf::class,
        Schema\GuardCost::class,
        Schema\Check::class,
        Schema\Package::class,
        Schema\Git::class,
        Schema\Cache::class,
        Schema\Runs::class,
    ];

    /** @var array<non-empty-string, KeyInfo>|null */
    private static ?array $keys = null;

    /**
     * All keys, in declaration order.
     *
     * @return array<non-empty-string, KeyInfo>
     */
    public static function keys(): array
    {
        return self::$keys ??= self::build();
    }

    public static function find(string $path): ?KeyInfo
    {
        return self::keys()[$path] ?? null;
    }

    /**
     * Keys and sections an unknown path was probably meant to be: a typo (`verification.sed` →
     * `verification.seed`), else a key put in the wrong place (`seed` → `verification.seed`), else
     * both (`rector.sed` → `verification.seed`).
     *
     * @return list<string>
     */
    public static function suggest(string $path): array
    {
        $candidates = [];
        foreach (self::keys() as $key => $_) {
            $parts = \explode('.', $key);
            for ($i = 1; $i <= \count($parts); ++$i) {
                $candidates[\implode('.', \array_slice($parts, 0, $i))] = true;
            }
        }

        unset($candidates[$path]);
        $candidates = \array_map(\strval(...), \array_keys($candidates));
        $close = DidYouMean::closest($path, $candidates);
        if ($close !== []) {
            return $close;
        }

        $last = static fn(string $p): string => \substr($p, (int) \strrpos('.' . $p, '.'));
        /** @var array<string, list<string>> $bySegment */
        $bySegment = [];
        foreach ($candidates as $candidate) {
            $bySegment[\strtolower($last($candidate))][] = $candidate;
        }

        $segment = \strtolower($last($path));
        $found = $bySegment[$segment] ?? [];
        foreach ($found === [] ? DidYouMean::closest($segment, \array_map(\strval(...), \array_keys($bySegment))) : [] as $near) {
            \array_push($found, ...$bySegment[$near]);
        }

        return \array_slice($found, 0, 3);
    }

    /**
     * Whether the path is a section that contains keys (`rector`, `rector.standard`).
     */
    public static function isSection(string $path): bool
    {
        $prefix = $path . '.';
        foreach (self::keys() as $key => $_) {
            if (\str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<non-empty-string, KeyInfo>
     */
    private static function build(): array
    {
        $keys = [];
        foreach (self::SECTIONS as $class) {
            $reflection = new \ReflectionClass($class);
            $defaults = $reflection->getDefaultProperties();

            foreach ($reflection->getProperties() as $property) {
                $attributes = $property->getAttributes(ConfigKey::class);
                if ($attributes === []) {
                    continue;
                }

                $key = $attributes[0]->newInstance();
                $type = $property->getType();
                $type instanceof \ReflectionNamedType or throw new \LogicException(\sprintf(
                    'Config property %s::$%s must have a single named type.',
                    $class,
                    $property->getName(),
                ));
                isset($keys[$key->path]) and throw new \LogicException("Duplicate config key `{$key->path}`.");

                $keys[$key->path] = new KeyInfo(
                    key: $key,
                    type: $type->getName(),
                    nullable: $type->allowsNull(),
                    default: $defaults[$property->getName()] ?? null,
                    class: $class,
                    property: $property->getName(),
                );
            }
        }

        return $keys;
    }
}
