<?php

declare(strict_types=1);

namespace Opmin\Harness;

/**
 * What the orchestrator needs to generate inputs, read from reflection of the loaded code:
 * signatures of the function under test and of constructors, properties, methods of types to mock,
 * enum cases. Types are structured, docblocks raw (the orchestrator parses phpdoc).
 *
 * @internal
 */
final class Reflector
{
    /**
     * @param array<array-key, mixed> $target
     * @return array<string, mixed>
     */
    public static function target(array $target): array
    {
        $function = Invoker::reflect($target);
        $result = self::signature($function);
        $result['file'] = $function->getFileName();
        $result['line'] = $function->getStartLine();
        $result['end_line'] = $function->getEndLine();
        if ($function instanceof \ReflectionMethod) {
            $result['static'] = $function->isStatic();
            $result['visibility'] = $function->isPrivate() ? 'private' : ($function->isProtected() ? 'protected' : 'public');
            $result['class'] = $function->getDeclaringClass()->getName();
        }

        if (($target['kind'] ?? '') === 'closure') {
            $wrapper = new \ReflectionFunction((string) $target['wrapper']);
            $result['uses'] = self::params($wrapper);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public static function class(string $name): array
    {
        if (!\class_exists($name) && !\interface_exists($name) && !\trait_exists($name) && !\enum_exists($name)) {
            return ['exists' => false, 'name' => $name];
        }

        $class = new \ReflectionClass($name);
        $result = [
            'exists' => true,
            'name' => $class->getName(),
            'kind' => match (true) {
                $class->isEnum() => 'enum',
                $class->isInterface() => 'interface',
                $class->isTrait() => 'trait',
                $class->isAbstract() => 'abstract',
                default => 'class',
            },
            'final' => $class->isFinal(),
            'internal' => $class->isInternal(),
            'instantiable' => $class->isInstantiable(),
            'doc' => self::doc($class->getDocComment()),
            'parents' => \array_merge(self::names(\class_parents($name)), self::names(\class_implements($name))),
        ];
        $constructor = $class->getConstructor();
        $result['constructor'] = $constructor === null ? null : self::signature($constructor) + [
            'visibility' => $constructor->isPublic() ? 'public' : 'private',
        ];

        $props = [];
        foreach ($class->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $props[] = [
                'name' => $property->getName(),
                'class' => $property->getDeclaringClass()->getName(),
                'type' => self::type($property->getType()),
                'readonly' => $property->isReadOnly(),
                'promoted' => $property->isPromoted(),
                'doc' => self::doc($property->getDocComment()),
            ];
        }
        $result['props'] = $props;

        $methods = [];
        foreach ($class->getMethods() as $method) {
            $methods[] = ['name' => $method->getName(), 'abstract' => $method->isAbstract(), 'static' => $method->isStatic()]
                + self::signature($method);
        }
        $result['methods'] = $methods;

        if ($class->isEnum()) {
            $enum = new \ReflectionEnum($name);
            $result['cases'] = \array_map(static fn(\ReflectionEnumUnitCase $c): string => $c->getName(), $enum->getCases());
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function signature(\ReflectionFunctionAbstract $function): array
    {
        return [
            'name' => $function->getName(),
            'params' => self::params($function),
            'return' => self::type($function->getReturnType()),
            'doc' => self::doc($function->getDocComment()),
            'by_ref_return' => $function->returnsReference(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function params(\ReflectionFunctionAbstract $function): array
    {
        $params = [];
        foreach ($function->getParameters() as $param) {
            $item = [
                'name' => $param->getName(),
                'type' => self::type($param->getType()),
                'optional' => $param->isOptional(),
                'variadic' => $param->isVariadic(),
                'by_ref' => $param->isPassedByReference(),
                'promoted' => $param->isPromoted(),
            ];
            if ($param->isDefaultValueAvailable()) {
                try {
                    $item['default'] = (new Describer())->describe($param->getDefaultValue());
                } catch (\Throwable) {
                    # A default that cannot be evaluated here (a missing constant).
                }
            }

            $params[] = $item;
        }

        return $params;
    }

    private static function doc(string|false $doc): ?string
    {
        return $doc === false ? null : $doc;
    }

    /**
     * @param array<string, string>|false $names
     * @return list<string>
     */
    private static function names(array|false $names): array
    {
        return $names === false ? [] : \array_values($names);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function type(?\ReflectionType $type): ?array
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof \ReflectionUnionType) {
            return ['union' => \array_map(static fn(\ReflectionType $t): ?array => self::type($t), $type->getTypes())];
        }

        if ($type instanceof \ReflectionIntersectionType) {
            return ['intersection' => \array_map(static fn(\ReflectionType $t): ?array => self::type($t), $type->getTypes())];
        }

        \assert($type instanceof \ReflectionNamedType);

        return ['name' => $type->getName(), 'builtin' => $type->isBuiltin(), 'nullable' => $type->allowsNull()];
    }
}
