<?php

declare(strict_types=1);

namespace Opmin\Harness;

/**
 * Builds values from the recipes of the protocol: scalars, arrays, objects through the constructor
 * or through properties (without the constructor), enum cases, mocks, recorded callables, and refs to
 * an object built earlier in the same input (identity matters).
 *
 * @internal
 */
final class Builder
{
    /** @var array<int, mixed> Objects by recipe id. */
    private array $refs = [];

    public function __construct(
        private Journal $journal,
    ) {}

    /**
     * @param list<array<array-key, mixed>> $recipes
     * @return list<mixed>
     */
    public function buildList(array $recipes): array
    {
        $values = [];
        foreach ($recipes as $recipe) {
            /** @psalm-suppress MixedAssignment */
            $values[] = $this->build($recipe);
        }

        return $values;
    }

    /**
     * @param array<array-key, mixed> $recipe
     */
    public function build(array $recipe): mixed
    {
        $type = (string) ($recipe['type'] ?? '');
        $value = match ($type) {
            'null' => null,
            'bool' => (bool) ($recipe['value'] ?? false),
            'int' => (int) ($recipe['value'] ?? 0),
            'float' => Value::floatFromString((string) ($recipe['value'] ?? '0')),
            'string' => Value::stringFrom($recipe),
            'array' => $this->array($recipe),
            'object' => $this->object($recipe),
            'enum' => \constant((string) $recipe['class'] . '::' . (string) $recipe['case']),
            'mock' => Mocks::create((string) ($recipe['interface'] ?? $recipe['class'] ?? ''), self::returns($recipe), self::id($recipe)),
            'callable' => $this->callable($recipe),
            'ref' => \array_key_exists((int) ($recipe['id'] ?? 0), $this->refs)
                ? $this->refs[(int) $recipe['id']]
                : throw new \InvalidArgumentException('Unknown ref id ' . (string) ($recipe['id'] ?? '')),
            default => throw new \InvalidArgumentException("Unknown recipe type `{$type}`"),
        };

        $id = self::id($recipe);
        $id === null || $type === 'ref' or $this->refs[$id] = $value;

        return $value;
    }

    /**
     * @param array<array-key, mixed> $recipe
     * @return array<string, list<array<array-key, mixed>>>
     */
    private static function returns(array $recipe): array
    {
        /** @var array<string, list<array<array-key, mixed>>> */
        return \is_array($recipe['returns'] ?? null) ? $recipe['returns'] : [];
    }

    /**
     * @param array<array-key, mixed> $recipe
     */
    private static function id(array $recipe): ?int
    {
        return isset($recipe['id']) && $recipe['type'] !== 'ref' ? (int) $recipe['id'] : null;
    }

    /**
     * @param array<array-key, mixed> $recipe
     * @return array<array-key, mixed>
     */
    private function array(array $recipe): array
    {
        $result = [];
        /** @var list<array{array<array-key, mixed>, array<array-key, mixed>}> $items */
        $items = $recipe['items'] ?? [];
        foreach ($items as [$key, $value]) {
            $k = $this->build($key);
            \is_int($k) || \is_string($k) or throw new \InvalidArgumentException('Array keys must be int or string');
            /** @psalm-suppress MixedAssignment */
            $result[$k] = $this->build($value);
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $recipe
     */
    private function object(array $recipe): object
    {
        $class = (string) ($recipe['class'] ?? '');
        if (($recipe['via'] ?? 'ctor') === 'ctor') {
            /** @var list<array<array-key, mixed>> $args */
            $args = $recipe['args'] ?? [];
            $values = $this->buildList($args);
            \class_exists($class) or throw new \InvalidArgumentException("Unknown class {$class}");

            /** @psalm-suppress MixedMethodCall */
            return new $class(...$values);
        }

        $reflection = new \ReflectionClass($class);
        $object = $reflection->newInstanceWithoutConstructor();
        $id = self::id($recipe);
        # Register before the properties: a property may refer back to the object.
        $id === null or $this->refs[$id] = $object;
        /** @var array<string, array<array-key, mixed>> $props */
        $props = $recipe['props'] ?? [];
        foreach ($props as $name => $propRecipe) {
            $this->setProperty($reflection, $object, (string) $name, $this->build($propRecipe));
        }

        return $object;
    }

    /**
     * `name` or `Class::name` (a private property of a parent class).
     */
    private function setProperty(\ReflectionClass $class, object $object, string $name, mixed $value): void
    {
        $declaring = $class;
        if (\str_contains($name, '::')) {
            $parts = \explode('::', $name, 2);
            $declaring = new \ReflectionClass($parts[0]);
            $name = $parts[1] ?? '';
        } else {
            while (!$declaring->hasProperty($name) && ($parent = $declaring->getParentClass()) !== false) {
                $declaring = $parent;
            }
        }

        if (!$declaring->hasProperty($name)) {
            # A dynamic property.
            $object->{$name} = $value;
            return;
        }

        $property = $declaring->getProperty($name);
        $scope = $property->getDeclaringClass()->getName();
        # Inside the declaring class: also initializes readonly properties.
        $set = \Closure::bind(static function (object $o, string $n, mixed $v): void {
            $o->{$n} = $v;
        }, null, $scope);
        $set === null and throw new \LogicException("Cannot bind to {$scope}");
        $set($object, $name, $value);
    }

    /**
     * A callable argument: records its calls in the journal and returns its results in turn.
     *
     * @param array<array-key, mixed> $recipe
     */
    private function callable(array $recipe): \Closure
    {
        $id = self::id($recipe);
        /** @var list<array<array-key, mixed>> $returns */
        $returns = $recipe['returns'] ?? [];
        $journal = $this->journal;
        $calls = 0;

        return function (mixed ...$args) use ($id, $returns, $journal, &$calls): mixed {
            $journal->record($id, '__invoke', \array_values($args));
            $n = $calls++;

            return $returns === [] ? null : $this->build($returns[\min($n, \count($returns) - 1)]);
        };
    }
}
