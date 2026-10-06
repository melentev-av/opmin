<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Input;

use Opmin\Module\Verification\Input;
use Opmin\Module\Verification\Property\InputShrinker;

/**
 * Smaller variants of any input, recipe by recipe: `null` first, then values of the same kind closer
 * to zero or emptiness — `0`, half, one step; a shorter or plainer string; an array with fewer
 * items; an object with smaller constructor arguments or properties; a mock with fewer prepared
 * results. Trailing optional arguments are dropped, a strict caller becomes a weak one. Every
 * candidate is strictly smaller, so shrinking ends.
 *
 * @psalm-type Recipe = array<string, mixed>
 * @internal
 */
final readonly class RecipeShrinker implements InputShrinker
{
    /**
     * @param non-negative-int $required Arguments that cannot be dropped.
     */
    public function __construct(
        private int $required = 0,
    ) {}

    /**
     * @param Recipe $recipe
     * @return iterable<Recipe>
     */
    public static function shrink(array $recipe, bool $nullable = true): iterable
    {
        $type = $recipe['type'] ?? null;
        if ($type === 'null' || $type === 'ref') {
            return;
        }

        $nullable and yield Recipes::null();
        switch ($type) {
            case 'bool':
                ($recipe['value'] ?? false) === true and yield Recipes::bool(false);
                return;
            case 'int':
                yield from self::int((int) ($recipe['value'] ?? 0));
                return;
            case 'float':
                yield from self::float((string) ($recipe['value'] ?? '0.0'));
                return;
            case 'string':
                yield from self::string(Recipes::bytes($recipe));
                return;
            case 'array':
                yield from self::array($recipe);
                return;
            case 'object':
                yield from self::object($recipe);
                return;
            case 'mock':
            case 'callable':
                yield from self::returns($recipe);
                return;
        }
    }

    public function candidates(Input $input): iterable
    {
        foreach ($this->raw($input) as $candidate) {
            yield Recipes::renumber($candidate);
        }
    }

    /**
     * @return iterable<Recipe>
     */
    private static function int(int $value): iterable
    {
        if ($value === 0) {
            return;
        }

        $seen = [$value => true];
        foreach ([0, \intdiv($value, 2), $value - ($value <=> 0)] as $candidate) {
            isset($seen[$candidate]) or yield Recipes::int($candidate);
            $seen[$candidate] = true;
        }
    }

    /**
     * @return iterable<Recipe>
     */
    private static function float(string $value): iterable
    {
        if ($value === '0.0') {
            return;
        }

        yield Recipes::float(0.0);
        $float = Recipes::floatFromString($value);
        if (\is_nan($float) || \is_infinite($float) || $value === '-0.0') {
            return;
        }

        $truncated = (float) (int) $float;
        \abs($float) < 1.0e18 && $truncated !== $float && $truncated !== 0.0 and yield Recipes::float($truncated);
        \abs($float) > 1.0 && \is_finite($float / 2.0) and yield Recipes::float($float / 2.0);
    }

    /**
     * @return iterable<Recipe>
     */
    private static function string(string $value): iterable
    {
        if ($value === '') {
            return;
        }

        yield Recipes::string('');
        $length = \strlen($value);
        $length > 1 and yield Recipes::string(\substr($value, 0, \intdiv($length, 2)));
        $length > 1 and yield Recipes::string(\substr($value, 0, -1));
        $plain = (string) \preg_replace('/[^\x20-\x7e]/', 'a', $value);
        $plain === $value || \strlen($plain) >= $length && \preg_match('/[^\x20-\x7e]/', $value) !== 1 or yield Recipes::string($plain);
    }

    /**
     * @param Recipe $recipe
     * @return iterable<Recipe>
     */
    private static function array(array $recipe): iterable
    {
        /** @var list<array{Recipe, Recipe}> $items */
        $items = \is_array($recipe['items'] ?? null) ? $recipe['items'] : [];
        if ($items === []) {
            return;
        }

        yield Recipes::array([]);
        foreach (\array_keys($items) as $i) {
            $fewer = $items;
            unset($fewer[$i]);
            \count($items) > 1 and yield Recipes::array(\array_values($fewer));
        }

        foreach ($items as $i => [$key, $value]) {
            foreach (self::shrink($value) as $smaller) {
                $changed = $items;
                $changed[$i] = [$key, $smaller];
                yield Recipes::array(\array_values($changed));
            }
        }
    }

    /**
     * @param Recipe $recipe
     * @return iterable<Recipe>
     */
    private static function object(array $recipe): iterable
    {
        if (($recipe['via'] ?? 'ctor') === 'ctor') {
            /** @var list<Recipe> $args */
            $args = \is_array($recipe['args'] ?? null) ? $recipe['args'] : [];
            foreach ($args as $i => $arg) {
                foreach (self::shrink($arg) as $smaller) {
                    $changed = $args;
                    $changed[$i] = $smaller;
                    yield ['args' => $changed] + $recipe;
                }
            }

            return;
        }

        /** @var array<string, Recipe> $props */
        $props = \is_array($recipe['props'] ?? null) ? $recipe['props'] : [];
        foreach ($props as $name => $prop) {
            foreach (self::shrink($prop) as $smaller) {
                yield ['props' => [$name => $smaller] + $props] + $recipe;
            }
        }
    }

    /**
     * @param Recipe $recipe
     * @return iterable<Recipe>
     */
    private static function returns(array $recipe): iterable
    {
        $returns = \is_array($recipe['returns'] ?? null) ? $recipe['returns'] : [];
        if ($returns === []) {
            return;
        }

        if (\array_is_list($returns)) {
            /** @var list<Recipe> $returns */
            yield ['returns' => \array_slice($returns, 0, -1)] + $recipe;
            foreach ($returns as $i => $value) {
                foreach (self::shrink($value) as $smaller) {
                    $changed = $returns;
                    $changed[$i] = $smaller;
                    yield ['returns' => $changed] + $recipe;
                }
            }

            return;
        }

        /** @var array<string, list<Recipe>> $returns */
        foreach (\array_keys($returns) as $method) {
            $fewer = $returns;
            unset($fewer[$method]);
            yield ['returns' => $fewer] + $recipe;
        }

        foreach ($returns as $method => $values) {
            foreach ($values as $i => $value) {
                foreach (self::shrink($value) as $smaller) {
                    $changed = $values;
                    $changed[$i] = $smaller;
                    yield ['returns' => [$method => $changed] + $returns] + $recipe;
                }
            }
        }
    }

    /**
     * @return iterable<Input>
     */
    private function raw(Input $input): iterable
    {
        $input->strict and yield $input->withStrict(false);
        \count($input->args) > $this->required and yield $input->withArgs(\array_slice($input->args, 0, -1));

        foreach ($input->args as $i => $arg) {
            foreach (self::shrink($arg) as $smaller) {
                $args = $input->args;
                $args[$i] = $smaller;
                yield $input->withArgs(\array_values($args));
            }
        }

        if ($input->receiver !== null) {
            foreach (self::shrink($input->receiver, nullable: false) as $smaller) {
                yield $input->withReceiver($smaller);
            }
        }

        foreach ($input->uses as $name => $use) {
            foreach (self::shrink($use) as $smaller) {
                yield $input->withUses([$name => $smaller] + $input->uses);
            }
        }
    }
}
