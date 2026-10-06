<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Input;

use Opmin\Module\Verification\Input;

/**
 * Helpers for recipes (`docs/harness-protocol.md`): constructors, the float encoding of the harness,
 * renumbering of object ids.
 *
 * @psalm-type Recipe = array<string, mixed>
 * @internal
 */
final class Recipes
{
    /**
     * @return Recipe
     */
    public static function null(): array
    {
        return ['type' => 'null'];
    }

    /**
     * @return Recipe
     */
    public static function bool(bool $value): array
    {
        return ['type' => 'bool', 'value' => $value];
    }

    /**
     * @return Recipe
     */
    public static function int(int $value): array
    {
        return ['type' => 'int', 'value' => $value];
    }

    /**
     * @return Recipe
     */
    public static function float(float $value): array
    {
        return ['type' => 'float', 'value' => self::floatToString($value)];
    }

    /**
     * @return Recipe
     */
    public static function string(string $value): array
    {
        return \preg_match('//u', $value) === 1
            ? ['type' => 'string', 'value' => $value]
            : ['type' => 'string', 'base64' => \base64_encode($value)];
    }

    /**
     * @param list<array{Recipe, Recipe}> $items
     * @return Recipe
     */
    public static function array(array $items): array
    {
        return ['type' => 'array', 'items' => $items];
    }

    /**
     * A list: keys 0, 1, 2…
     *
     * @param list<Recipe> $values
     * @return Recipe
     */
    public static function list(array $values): array
    {
        $items = [];
        foreach ($values as $i => $value) {
            $items[] = [self::int($i), $value];
        }

        return self::array($items);
    }

    /**
     * The harness's encoding (`Opmin\Harness\Value::floatToString()`).
     */
    public static function floatToString(float $value): string
    {
        return match (true) {
            \is_nan($value) => 'NAN',
            \is_infinite($value) => $value > 0 ? 'INF' : '-INF',
            $value === 0.0 => \fdiv(1.0, $value) < 0 ? '-0.0' : '0.0',
            default => \var_export($value, true),
        };
    }

    public static function floatFromString(string $value): float
    {
        return match ($value) {
            'NAN' => \NAN,
            'INF' => \INF,
            '-INF' => -\INF,
            '-0.0' => -0.0,
            default => (float) $value,
        };
    }

    /**
     * String value of a string recipe.
     *
     * @param Recipe $recipe
     */
    public static function bytes(array $recipe): string
    {
        return isset($recipe['base64']) ? (string) \base64_decode((string) $recipe['base64'], true) : (string) ($recipe['value'] ?? '');
    }

    /**
     * Gives objects, mocks and callables unique ids in build order (`this`, `uses`, `args`) and points
     * refs at the new ids; a ref to an id that is not built before it becomes `null`.
     */
    public static function renumber(Input $input): Input
    {
        $map = [];
        $next = 0;
        $receiver = $input->receiver === null ? null : self::renumberRecipe($input->receiver, $map, $next);
        $uses = [];
        foreach ($input->uses as $name => $use) {
            $uses[$name] = self::renumberRecipe($use, $map, $next);
        }

        $args = [];
        foreach ($input->args as $arg) {
            $args[] = self::renumberRecipe($arg, $map, $next);
        }

        return new Input($args, $receiver, $uses, $input->strict);
    }

    /**
     * Stable identity of an input, for removing duplicates.
     */
    public static function key(Input $input): string
    {
        return \hash('xxh128', (string) \json_encode($input->toArray(), \JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @param Recipe $recipe
     * @param array<int, int> $map Old id => new id.
     * @return Recipe
     */
    private static function renumberRecipe(array $recipe, array &$map, int &$next): array
    {
        $type = $recipe['type'] ?? null;
        if ($type === 'ref') {
            $old = (int) ($recipe['id'] ?? 0);

            return isset($map[$old]) ? ['type' => 'ref', 'id' => $map[$old]] : self::null();
        }

        # Inner recipes are built before the object registers its id (`via: ctor`, mocks).
        if (\is_array($recipe['args'] ?? null)) {
            $recipe['args'] = self::renumberList($recipe['args'], $map, $next);
        }

        if (\is_array($recipe['items'] ?? null)) {
            $items = [];
            /** @var list<array{Recipe, Recipe}> $pairs */
            $pairs = $recipe['items'];
            foreach ($pairs as [$key, $value]) {
                $items[] = [self::renumberRecipe($key, $map, $next), self::renumberRecipe($value, $map, $next)];
            }

            $recipe['items'] = $items;
        }

        if (\is_array($recipe['returns'] ?? null)) {
            $returns = $recipe['returns'];
            if (\array_is_list($returns)) {
                $recipe['returns'] = self::renumberList($returns, $map, $next);
            } else {
                $byMethod = [];
                /** @var array<string, list<Recipe>> $returns */
                foreach ($returns as $method => $list) {
                    $byMethod[$method] = self::renumberList($list, $map, $next);
                }

                $recipe['returns'] = $byMethod;
            }
        }

        if (\in_array($type, ['object', 'mock', 'callable'], true)) {
            $id = ++$next;
            isset($recipe['id']) and $map[(int) $recipe['id']] = $id;
            $recipe['id'] = $id;
        }

        if (\is_array($recipe['props'] ?? null)) {
            # Properties are built after the object is registered: they may refer back to it.
            $props = [];
            /** @var array<string, Recipe> $values */
            $values = $recipe['props'];
            foreach ($values as $name => $value) {
                $props[$name] = self::renumberRecipe($value, $map, $next);
            }

            $recipe['props'] = $props;
        }

        return $recipe;
    }

    /**
     * @param array<array-key, mixed> $recipes
     * @param array<int, int> $map
     * @return list<Recipe>
     */
    private static function renumberList(array $recipes, array &$map, int &$next): array
    {
        $result = [];
        /** @var Recipe $recipe */
        foreach ($recipes as $recipe) {
            $result[] = self::renumberRecipe($recipe, $map, $next);
        }

        return $result;
    }
}
