<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize\Rector;

use Opmin\Module\Config\Schema;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\Contract\Rector\RectorInterface;
use Opmin\Module\Release\Installation;
use Rector\Config\RectorConfig;
use Rector\Configuration\Option;
use Rector\Configuration\Parameter\SimpleParameterProvider;
use Symfony\Component\Process\Process;

/**
 * The list of Stage A rules in the order they are applied: opmin's own rules first, then (when
 * enabled) the standard ones, sets expanded into single rules (brief, «Stage A — Rector»).
 *
 * @internal
 */
final class RuleCatalog
{
    /**
     * @param bool|null $withStandard `--with-standard-rector` / `--without-standard-rector`; null — the config.
     * @param list<string> $only `--rector-rule`: only these rules (FQCN).
     * @param non-empty-string|null $phpTarget `php.target` as `8.3`, for `UP_TO_PHP_TARGET`.
     * @return list<RuleSpec>
     * @throws \InvalidArgumentException On an unknown rule or set.
     */
    public static function build(Schema\Rector $rector, Schema\RectorStandard $standard, ?bool $withStandard, array $only, ?string $phpTarget): array
    {
        $rules = [];
        foreach ($rector->customRules as $class => $options) {
            if ($options === false) {
                continue;
            }

            $rules[$class] = self::custom($class, $options);
        }

        if ($withStandard ?? $standard->enabled) {
            foreach (self::standard($standard, $phpTarget) as $spec) {
                $rules[$spec->class] ??= $spec;
            }
        }

        if ($only === []) {
            return \array_values($rules);
        }

        $selected = [];
        foreach ($only as $class) {
            $class = \ltrim($class, '\\');
            $selected[] = $rules[$class] ?? (\str_starts_with($class, 'Opmin\\') ? self::custom($class, []) : self::single($class));
        }

        return $selected;
    }

    /**
     * Set names of `rector.standard.sets` to set files: `DEAD_CODE` → `SetList::DEAD_CODE`,
     * `UP_TO_PHP_TARGET` → `LevelSetList::UP_TO_PHP_83` for `php.target` 8.3.
     *
     * @return non-empty-string
     */
    public static function setFile(string $name, ?string $phpTarget): string
    {
        if ($name === 'UP_TO_PHP_TARGET') {
            $phpTarget === null and throw new \InvalidArgumentException(
                'The set UP_TO_PHP_TARGET needs php.target (or a PHP constraint in composer.json).',
            );
            $name = 'UP_TO_PHP_' . \str_replace('.', '', $phpTarget);
            $constant = 'Rector\Set\ValueObject\LevelSetList::' . $name;
        } else {
            $constant = 'Rector\Set\ValueObject\SetList::' . $name;
        }

        \defined($constant) or throw new \InvalidArgumentException("Unknown Rector set `{$name}` in rector.standard.sets.");
        /** @var non-empty-string $file */
        $file = \constant($constant);

        $real = \realpath($file);

        return $real === false || $real === '' ? $file : $real;
    }

    /**
     * Rules a set registers (imports followed), read in a fresh PHP process: Rector keeps
     * registered rules in static state.
     *
     * @param list<non-empty-string> $sets Set files.
     * @return array<non-empty-string, list<class-string>>
     */
    public static function expand(array $sets): array
    {
        $installation = Installation::current();
        $process = new Process([...$installation->command(), ...$sets], env: ['OPMIN_INTERNAL' => 'expand-sets', 'OPMIN_NO_DELEGATE' => '1'], timeout: 120);
        $process->run();
        /** @var mixed $data */
        $data = \json_decode($process->getOutput(), true);
        \is_array($data) && $process->isSuccessful() or throw new \RuntimeException('Cannot expand the Rector sets: ' . \trim($process->getErrorOutput() . $process->getOutput()));

        /** @var array<non-empty-string, list<class-string>> $data */
        return $data;
    }

    /**
     * {@see self::expand()} inside the fresh process (`OPMIN_INTERNAL=expand-sets`).
     *
     * @param list<string> $sets
     * @return array<string, list<string>>
     */
    public static function expandHere(array $sets): array
    {
        $result = [];
        foreach ($sets as $set) {
            SimpleParameterProvider::setParameter(Option::REGISTERED_RECTOR_RULES, []);
            (new RectorConfig())->import($set);
            /** @var list<string> $rules */
            $rules = SimpleParameterProvider::provideArrayParameter(Option::REGISTERED_RECTOR_RULES);
            $result[$set] = \array_values(\array_unique($rules));
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function custom(string $class, array $options): RuleSpec
    {
        self::ensureRule($class, 'rector.custom_rules');
        /** @var class-string $class */
        $configurable = \is_a($class, ConfigurableRectorInterface::class, true);

        return new RuleSpec(
            $class,
            $configurable ? $options : null,
            custom: true,
            executedGain: \defined($class . '::EXECUTED_GAIN') && \constant($class . '::EXECUTED_GAIN') === true,
        );
    }

    private static function single(string $class): RuleSpec
    {
        self::ensureRule($class, 'rector.standard.rules');
        /** @var class-string $class */
        # A configurable rule without options would fail in Rector: such rules come from sets only.
        \is_a($class, ConfigurableRectorInterface::class, true) and throw new \InvalidArgumentException(
            "The Rector rule `{$class}` needs configuration: enable the set that configures it instead.",
        );

        return new RuleSpec($class);
    }

    /**
     * @return list<RuleSpec>
     */
    private static function standard(Schema\RectorStandard $standard, ?string $phpTarget): array
    {
        $skip = \array_flip(\array_map(static fn(string $c): string => \ltrim($c, '\\'), $standard->skip));
        $files = \array_map(static fn(string $name): string => self::setFile($name, $phpTarget), $standard->sets);
        $specs = [];
        foreach ($files === [] ? [] : self::expand($files) as $file => $rules) {
            foreach ($rules as $class) {
                if (isset($skip[$class]) || isset($specs[$class])) {
                    continue;
                }

                $others = \array_values(\array_filter($rules, static fn(string $other): bool => $other !== $class));
                $specs[$class] = new RuleSpec($class, set: $file, skip: $others);
            }
        }

        foreach ($standard->rules as $class) {
            $class = \ltrim($class, '\\');
            isset($skip[$class]) || isset($specs[$class]) or $specs[$class] = self::single($class);
        }

        return \array_values($specs);
    }

    private static function ensureRule(string $class, string $key): void
    {
        \class_exists($class) && \is_a($class, RectorInterface::class, true) or throw new \InvalidArgumentException(
            "Unknown Rector rule `{$class}` in {$key}: check the class name against the installed Rector.",
        );
    }
}
