<?php

declare(strict_types=1);

namespace Opmin\Harness;

/**
 * Mocks of interfaces and abstract classes, generated as code: every method records its call in the
 * journal and returns the next value from its recipe list (the last one repeats), or a deterministic
 * default by return type.
 *
 * One class per mocked type (`Opmin\Harness\Mock\M<hash>`); instances keep their state here.
 *
 * @internal
 */
final class Mocks
{
    /** @var array<string, class-string> Mocked type => generated class. */
    private static array $classes = [];

    /** @var array<int, array{id: int|null, returns: array<string, list<array<array-key, mixed>>>, calls: array<string, int>}> By spl_object_id. */
    private static array $state = [];

    private static ?Journal $journal = null;
    private static ?Builder $builder = null;

    public static function begin(Journal $journal, Builder $builder): void
    {
        self::$journal = $journal;
        self::$builder = $builder;
        self::$state = [];
    }

    public static function end(): void
    {
        self::$journal = null;
        self::$builder = null;
        self::$state = [];
    }

    /**
     * @param array<string, list<array<array-key, mixed>>> $returns Method => recipes of the results.
     */
    public static function create(string $type, array $returns, ?int $id): object
    {
        $class = self::$classes[\strtolower($type)] ??= self::generate($type);
        $mock = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $lower = [];
        foreach ($returns as $method => $recipes) {
            $lower[\strtolower((string) $method)] = $recipes;
        }

        self::$state[\spl_object_id($mock)] = ['id' => $id, 'returns' => $lower, 'calls' => []];

        return $mock;
    }

    /**
     * Called by the generated methods.
     *
     * @param list<mixed> $args
     */
    public static function call(object $mock, string $method, array $args, ?string $returnType): mixed
    {
        $key = \spl_object_id($mock);
        $state = self::$state[$key] ?? ['id' => null, 'returns' => [], 'calls' => []];
        self::$journal?->record($state['id'], $method, $args);
        $lower = \strtolower($method);
        $n = $state['calls'][$lower] ?? 0;
        self::$state[$key]['calls'][$lower] = $n + 1;
        $recipes = $state['returns'][$lower] ?? [];
        if ($recipes !== [] && self::$builder !== null) {
            return self::$builder->build($recipes[\min($n, \count($recipes) - 1)]);
        }

        return self::default($mock, $returnType);
    }

    /**
     * Deterministic result for a method without a recipe.
     */
    public static function default(object $mock, ?string $type): mixed
    {
        if ($type === null || $type === '' || $type === 'mixed' || $type === 'void' || $type === 'null' || $type[0] === '?') {
            return null;
        }

        $first = \explode('|', \str_replace(['(', ')'], '', $type))[0];
        if (\str_contains($type, '|null') || \str_contains($type, 'null|')) {
            return null;
        }

        return match (\strtolower($first)) {
            'int' => 0,
            'float' => 0.0,
            'string' => '',
            'bool', 'false' => false,
            'true' => true,
            'array', 'iterable' => [],
            'static', 'self' => $mock,
            'callable', 'closure', '\closure' => static fn(): mixed => null,
            'object' => new \stdClass(),
            default => self::defaultObject(\ltrim($first, '\\')),
        };
    }

    private static function defaultObject(string $class): object
    {
        if (\interface_exists($class) || (\class_exists($class) && (new \ReflectionClass($class))->isAbstract())) {
            return self::create($class, [], null);
        }

        \class_exists($class) or throw new \LogicException("Mock cannot return an unknown type {$class}");

        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    /**
     * @return class-string
     */
    private static function generate(string $type): string
    {
        $reflection = new \ReflectionClass($type);
        $reflection->isInterface() || ($reflection->isAbstract() && !$reflection->isFinal())
            or throw new \LogicException("Only interfaces and abstract classes can be mocked, {$type} is neither");
        $reflection->isInterface() && !$reflection->isSubclassOf(\Traversable::class) || $reflection->isSubclassOf(\Iterator::class)
            || $reflection->isSubclassOf(\IteratorAggregate::class) || !$reflection->isInterface()
            or throw new \LogicException("{$type} extends Traversable only and cannot be implemented");

        $name = 'M' . \md5($reflection->getName());
        $methods = [];
        foreach ($reflection->getMethods() as $method) {
            if (!$method->isAbstract() || $method->isPrivate()) {
                continue;
            }

            $methods[] = self::method($method, $reflection);
        }

        $relation = $reflection->isInterface() ? 'implements' : 'extends';
        $code = "namespace Opmin\\Harness\\Mock; final class {$name} {$relation} \\{$reflection->getName()} {\n"
            . \implode("\n", $methods) . "\n}";
        eval($code);

        /** @var class-string */
        return "Opmin\\Harness\\Mock\\{$name}";
    }

    private static function method(\ReflectionMethod $method, \ReflectionClass $owner): string
    {
        $params = [];
        foreach ($method->getParameters() as $param) {
            $text = self::type($param->getType(), $owner);
            $text === '' or $text .= ' ';
            $param->isPassedByReference() and $text .= '&';
            $param->isVariadic() and $text .= '...';
            $text .= '$' . $param->getName();
            if ($param->isOptional() && !$param->isVariadic()) {
                $text .= ' = ' . self::defaultValue($param);
            }

            $params[] = $text;
        }

        $return = $method->getReturnType();
        $returnText = self::type($return, $owner);
        $static = $method->isStatic();
        $self = $static ? 'static::class' : '$this';
        $call = $static
            ? "\\Opmin\\Harness\\Mocks::call(new \\stdClass(), " . \var_export($method->getName(), true) . ', \func_get_args(), ' . \var_export($returnText, true) . ')'
            : "\\Opmin\\Harness\\Mocks::call({$self}, " . \var_export($method->getName(), true) . ', \func_get_args(), ' . \var_export($returnText, true) . ')';
        $body = match ($returnText) {
            'void' => "{$call};",
            'never' => "{$call}; throw new \\LogicException('A never-returning mock method returned');",
            default => "return {$call};",
        };

        return \sprintf(
            '    public %sfunction %s%s(%s)%s { %s }',
            $static ? 'static ' : '',
            $method->returnsReference() ? '&' : '',
            $method->getName(),
            \implode(', ', $params),
            $returnText === '' ? '' : ': ' . $returnText,
            $body,
        );
    }

    /**
     * Type as code, with `self` and `parent` resolved: in the generated class they would mean it.
     */
    private static function type(?\ReflectionType $type, \ReflectionClass $owner): string
    {
        if ($type === null) {
            return '';
        }

        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $glue = $type instanceof \ReflectionUnionType ? '|' : '&';
            $parts = [];
            foreach ($type->getTypes() as $part) {
                $text = self::type($part, $owner);
                # A DNF part of a union (PHP 8.2+).
                $parts[] = $glue === '|' && \str_contains($text, '&') ? "({$text})" : $text;
            }

            return \implode($glue, $parts);
        }

        \assert($type instanceof \ReflectionNamedType);
        $name = $type->getName();
        $lower = \strtolower($name);
        if ($lower === 'self') {
            $name = '\\' . $owner->getName();
        } elseif ($lower === 'parent') {
            $parent = $owner->getParentClass();
            $name = $parent === false ? 'mixed' : '\\' . $parent->getName();
        } elseif (!$type->isBuiltin() && $lower !== 'static') {
            $name = '\\' . $name;
        }

        return $type->allowsNull() && $lower !== 'mixed' && $lower !== 'null' ? '?' . $name : $name;
    }

    private static function defaultValue(\ReflectionParameter $param): string
    {
        if ($param->isDefaultValueAvailable()) {
            if ($param->isDefaultValueConstant()) {
                $constant = $param->getDefaultValueConstantName();

                return \str_contains($constant, '::') ? '\\' . \ltrim($constant, '\\') : '\\' . \ltrim($constant, '\\');
            }

            try {
                $value = $param->getDefaultValue();
                if (!\is_object($value)) {
                    return \var_export($value, true);
                }
            } catch (\Throwable) {
                # Fall through to null.
            }
        }

        return 'null';
    }
}
