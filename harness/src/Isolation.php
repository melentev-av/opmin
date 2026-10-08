<?php

declare(strict_types=1);

namespace Opmin\Harness;

/**
 * Global state around a call: globals, superglobals and static properties of user classes are
 * snapshotted before, compared after (what the call changed is part of its behavior) and restored,
 * so the next call starts from the same state. Objects are restored by reference: their inner state
 * is not rolled back (the determinism check catches the leak). `static` variables of functions
 * cannot be reset in a running process: the orchestrator uses a fresh worker for those.
 *
 * @internal
 */
final class Isolation
{
    private const SUPERGLOBALS = ['_GET', '_POST', '_COOKIE', '_FILES', '_SERVER', '_ENV', '_REQUEST', '_SESSION'];

    /** @var array<class-string, list<\ReflectionProperty>> Static properties of user classes. */
    private static array $statics = [];

    private static int $classCount = 0;

    /**
     * @return array{globals: array<string, mixed>, super: array<string, mixed>, statics: array<string, mixed>}
     */
    public static function snapshot(): array
    {
        $globals = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($GLOBALS as $name => $value) {
            $name === 'GLOBALS' || \in_array($name, self::SUPERGLOBALS, true) or $globals[$name] = $value;
        }

        $super = [];
        foreach (self::SUPERGLOBALS as $name) {
            \array_key_exists($name, $GLOBALS) and $super[$name] = $GLOBALS[$name];
        }

        $statics = [];
        foreach (self::staticProperties() as $class => $properties) {
            foreach ($properties as $property) {
                $property->isInitialized() and $statics[$class . '::$' . $property->getName()] = $property->getValue();
            }
        }

        return ['globals' => $globals, 'super' => $super, 'statics' => $statics];
    }

    /**
     * What changed since the snapshot: name => new value (`{"type": "unset"}` when removed).
     *
     * @param array{globals: array<string, mixed>, super: array<string, mixed>, statics: array<string, mixed>} $before
     * @return array{globals: array<string, mixed>, statics: array<string, mixed>}
     */
    public static function changes(array $before, Describer $describer): array
    {
        $now = self::snapshot();
        $globals = self::diff($before['globals'] + $before['super'], $now['globals'] + $now['super'], $describer);
        $statics = self::diff($before['statics'], $now['statics'], $describer);
        \ksort($globals);
        \ksort($statics);

        return ['globals' => $globals, 'statics' => $statics];
    }

    /**
     * @param array{globals: array<string, mixed>, super: array<string, mixed>, statics: array<string, mixed>} $before
     */
    public static function restore(array $before): void
    {
        foreach (\array_keys($GLOBALS) as $name) {
            if ($name !== 'GLOBALS' && !\in_array($name, self::SUPERGLOBALS, true) && !\array_key_exists($name, $before['globals'])) {
                unset($GLOBALS[$name]);
            }
        }

        /** @psalm-suppress MixedAssignment */
        foreach ($before['globals'] + $before['super'] as $name => $value) {
            $GLOBALS[$name] = $value;
        }

        foreach (self::staticProperties() as $class => $properties) {
            foreach ($properties as $property) {
                $key = $class . '::$' . $property->getName();
                \array_key_exists($key, $before['statics']) and $property->setValue(null, $before['statics'][$key]);
            }
        }
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, mixed>
     */
    private static function diff(array $before, array $after, Describer $describer): array
    {
        $changes = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($after as $name => $value) {
            if (!\array_key_exists($name, $before) || $before[$name] !== $value) {
                $changes[$name] = $describer->describe($value);
            }
        }

        foreach (\array_keys($before) as $name) {
            \array_key_exists($name, $after) or $changes[$name] = ['type' => 'unset'];
        }

        return $changes;
    }

    /**
     * @return array<class-string, list<\ReflectionProperty>>
     */
    private static function staticProperties(): array
    {
        $classes = \get_declared_classes();
        if (\count($classes) === self::$classCount) {
            return self::$statics;
        }

        foreach (\array_slice($classes, self::$classCount) as $class) {
            $reflection = new \ReflectionClass($class);
            if ($reflection->isInternal() || \str_starts_with($class, 'Opmin\Harness\\')) {
                continue;
            }

            $properties = [];
            foreach ($reflection->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                # Inherited statics are shared with the parent unless redeclared.
                $property->getDeclaringClass()->getName() === $reflection->getName() and $properties[] = $property;
            }

            $properties === [] or self::$statics[$class] = $properties;
        }

        self::$classCount = \count($classes);

        return self::$statics;
    }
}
