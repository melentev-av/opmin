<?php

declare(strict_types=1);

namespace Opmin\Harness;

/**
 * Calls the function under test. The call site decides the typing mode of the arguments, so there
 * are two call sites: compiled with and without `strict_types=1`. Non-public methods are called
 * through closures bound to the declaring class (`Closure::bind`).
 *
 * Targets: `{"kind": "function", "name"}`, `{"kind": "method", "class", "name"}` (static, instance or
 * constructor), `{"kind": "closure", "wrapper", "scope"}` (a generated function that returns the
 * closure, its parameters are the `use` variables), `{"kind": "hook", "class", "property", "hook"}`.
 *
 * @internal
 */
final class Invoker
{
    /** Call sites; compiled once per typing mode. */
    private const CALLS = <<<'PHP'
        function func(string $name, array &$args): mixed
        {
            return $name(...$args);
        }

        function construct(string $class, array &$args): object
        {
            return \Closure::bind(static fn(array &$a): object => new $class(...$a), null, $class)($args);
        }

        function method(?object $object, string $scope, string $name, array &$args): mixed
        {
            return $object === null
                ? \Closure::bind(static fn(array &$a): mixed => $scope::$name(...$a), null, $scope)($args)
                : \Closure::bind(fn(array &$a): mixed => $this->$name(...$a), $object, $scope)($args);
        }

        function closure(\Closure $closure, array &$args): mixed
        {
            return $closure(...$args);
        }

        function get(object $object, string $scope, string $property): mixed
        {
            return \Closure::bind(fn(): mixed => $this->$property, $object, $scope)();
        }

        function set(object $object, string $scope, string $property, array &$args): void
        {
            \Closure::bind(function (array &$a) use ($property): void { $this->$property = $a[0] ?? null; }, $object, $scope)($args);
        }

        function bound(?object $object, string $scope, string $name): \Closure
        {
            return $object === null
                ? \Closure::bind(static fn(array &$a): mixed => $scope::$name(...$a), null, $scope)
                : \Closure::bind(fn(array &$a): mixed => $this->$name(...$a), $object, $scope);
        }

        function maker(string $class): \Closure
        {
            return \Closure::bind(static fn(array &$a): object => new $class(...$a), null, $class);
        }

        function named(string $name): \Closure
        {
            return static fn(array &$a): mixed => $name(...$a);
        }

        function wrapped(\Closure $closure): \Closure
        {
            return static fn(array &$a): mixed => $closure(...$a);
        }
        PHP;

    private static bool $compiled = false;

    /**
     * @param array<array-key, mixed> $target
     * @param list<mixed> $args By reference: by-ref parameters change them.
     * @param array<string, mixed> $uses
     */
    public static function invoke(array $target, array &$args, ?object $receiver, array $uses, bool $strict): mixed
    {
        self::compile();
        $ns = $strict ? 'Opmin\Harness\Call\Strict\\' : 'Opmin\Harness\Call\Weak\\';
        $kind = (string) ($target['kind'] ?? '');

        switch ($kind) {
            case 'function':
                return ($ns . 'func')((string) $target['name'], $args);
            case 'method':
                $method = new \ReflectionMethod((string) $target['class'], (string) $target['name']);
                $scope = $method->getDeclaringClass()->getName();
                if ($method->isConstructor()) {
                    return ($ns . 'construct')((string) $target['class'], $args);
                }

                if (!$method->isStatic() && $receiver === null) {
                    throw new \LogicException("Instance method {$scope}::{$method->getName()} needs `this`");
                }

                return ($ns . 'method')($method->isStatic() ? null : $receiver, $method->isStatic() ? (string) $target['class'] : $scope, $method->getName(), $args);
            case 'closure':
                return ($ns . 'closure')(self::closure($target, $uses, $receiver), $args);
            case 'hook':
                $receiver === null and throw new \LogicException('A property hook needs `this`');
                $property = (string) $target['property'];
                $scope = (new \ReflectionProperty((string) $target['class'], $property))->getDeclaringClass()->getName();
                if (($target['hook'] ?? 'get') === 'set') {
                    ($ns . 'set')($receiver, $scope, $property, $args);
                    return null;
                }

                return ($ns . 'get')($receiver, $scope, $property);
        }

        throw new \InvalidArgumentException("Unknown target kind `{$kind}`");
    }

    /**
     * The call of the target as a closure resolved once (`bench`): reflection and binding stay out
     * of the measured calls. Property hooks fall back to {@see self::invoke()}.
     *
     * @param array<array-key, mixed> $target
     * @param array<string, mixed> $uses
     * @return \Closure(list<mixed>): mixed
     */
    public static function prepare(array $target, ?object $receiver, array $uses, bool $strict): \Closure
    {
        self::compile();
        $ns = $strict ? 'Opmin\Harness\Call\Strict\\' : 'Opmin\Harness\Call\Weak\\';
        $kind = (string) ($target['kind'] ?? '');
        if ($kind === 'function') {
            /** @var \Closure(list<mixed>): mixed */
            return ($ns . 'named')((string) $target['name']);
        }

        if ($kind === 'closure') {
            /** @var \Closure(list<mixed>): mixed */
            return ($ns . 'wrapped')(self::closure($target, $uses, $receiver));
        }

        if ($kind === 'method') {
            $method = new \ReflectionMethod((string) $target['class'], (string) $target['name']);
            if ($method->isConstructor()) {
                /** @var \Closure(list<mixed>): mixed */
                return ($ns . 'maker')((string) $target['class']);
            }

            if (!$method->isStatic() && $receiver === null) {
                throw new \LogicException("Instance method {$method->class}::{$method->getName()} needs `this`");
            }

            $scope = $method->isStatic() ? (string) $target['class'] : $method->getDeclaringClass()->getName();

            /** @var \Closure(list<mixed>): mixed */
            return ($ns . 'bound')($method->isStatic() ? null : $receiver, $scope, $method->getName());
        }

        return static function (array $args) use ($target, $receiver, $uses, $strict): mixed {
            /** @var list<mixed> $args */
            return self::invoke($target, $args, $receiver, $uses, $strict);
        };
    }

    /**
     * @param array<array-key, mixed> $target
     */
    public static function reflect(array $target): \ReflectionFunctionAbstract
    {
        $kind = (string) ($target['kind'] ?? '');

        return match ($kind) {
            'function' => new \ReflectionFunction((string) $target['name']),
            'method' => new \ReflectionMethod((string) $target['class'], (string) $target['name']),
            'closure' => new \ReflectionFunction(self::closure($target, [], null)),
            'hook' => self::hook($target),
            default => throw new \InvalidArgumentException("Unknown target kind `{$kind}`"),
        };
    }

    /**
     * The file and the first line of the function, for error lines relative to it.
     *
     * @param array<array-key, mixed> $target
     * @return array{string|null, int}
     */
    public static function location(array $target): array
    {
        try {
            $function = self::reflect($target);
            $file = $function->getFileName();

            return [$file === false ? null : $file, (int) $function->getStartLine()];
        } catch (\Throwable) {
            return [null, 0];
        }
    }

    /**
     * @param array<array-key, mixed> $target
     * @param array<string, mixed> $uses
     */
    private static function closure(array $target, array $uses, ?object $receiver): \Closure
    {
        $wrapper = (string) ($target['wrapper'] ?? '');
        $values = [];
        foreach ((new \ReflectionFunction($wrapper))->getParameters() as $param) {
            /** @psalm-suppress MixedAssignment */
            $values[] = $uses[$param->getName()] ?? null;
        }

        $closure = $wrapper(...$values);
        $closure instanceof \Closure or throw new \LogicException("Closure wrapper {$wrapper} did not return a closure");
        $scope = isset($target['scope']) ? (string) $target['scope'] : null;
        if ($scope !== null) {
            $bound = (new \ReflectionFunction($closure))->getClosureThis() === null && $receiver === null
                ? \Closure::bind($closure, null, $scope)
                : \Closure::bind($closure, $receiver, $scope);
            $bound instanceof \Closure or throw new \LogicException("Closure cannot be bound to {$scope}");
            $closure = $bound;
        }

        return $closure;
    }

    /**
     * @param array<array-key, mixed> $target
     */
    private static function hook(array $target): \ReflectionMethod
    {
        $property = new \ReflectionProperty((string) $target['class'], (string) $target['property']);
        \method_exists($property, 'getHooks') or throw new \LogicException('Property hooks need PHP 8.4');
        $name = ($target['hook'] ?? 'get') === 'set' ? 'set' : 'get';
        # ReflectionProperty::getHooks() (PHP 8.4): hook name => ReflectionMethod.
        /** @var array<string, \ReflectionMethod> $hooks */
        $hooks = \call_user_func([$property, 'getHooks']);

        return $hooks[$name] ?? throw new \LogicException("No {$name} hook on {$property->class}::\${$property->name}");
    }

    private static function compile(): void
    {
        if (self::$compiled) {
            return;
        }

        eval("namespace Opmin\\Harness\\Call\\Weak;\n" . self::CALLS);
        eval("declare(strict_types=1);\nnamespace Opmin\\Harness\\Call\\Strict;\n" . self::CALLS);
        self::$compiled = true;
    }
}
