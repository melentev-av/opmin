<?php

declare(strict_types=1);

namespace Opmin\Harness;

/**
 * Turns values into the normalized descriptions of the protocol: scalars, arrays as ordered
 * key-value pairs, objects structurally (class and properties through reflection) with ids in
 * order of first appearance instead of `spl_object_id()`, cycles as `ref`, generators iterated.
 *
 * One describer serves one `call`: an object keeps its id across the return value, the arguments,
 * `$this` and the mock journal, so identity relations are compared too.
 *
 * @internal
 */
final class Describer
{
    private const MAX_DEPTH = 64;
    private const MAX_NODES = 20000;
    private const MAX_ITEMS = 1000;

    /** Internal properties of exceptions that are not behavior (location, trace) or are described apart. */
    private const EXCEPTION_PROPS = ['message', 'string', 'code', 'file', 'line', 'trace', 'previous'];

    private \SplObjectStorage $ids;
    private int $next = 0;
    private int $nodes = 0;

    /** @var array<int, true> Objects on the current path (by spl_object_id), for cycles. */
    private array $path = [];

    public function __construct()
    {
        $this->ids = new \SplObjectStorage();
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<array<string, mixed>>
     */
    public function describeList(array $values): array
    {
        $result = [];
        /** @var mixed $value */
        foreach ($values as $value) {
            $result[] = $this->describe($value);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(mixed $value, int $depth = 0): array
    {
        if (++$this->nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            return ['type' => 'truncated'];
        }

        return match (true) {
            $value === null => ['type' => 'null'],
            \is_bool($value) => ['type' => 'bool', 'value' => $value],
            \is_int($value) => ['type' => 'int', 'value' => $value],
            \is_float($value) => ['type' => 'float', 'value' => Value::floatToString($value)],
            \is_string($value) => Value::string($value),
            \is_array($value) => $this->array($value, $depth),
            \is_object($value) => $this->object($value, $depth),
            default => ['type' => 'resource', 'kind' => \gettype($value) === 'resource' ? \get_resource_type($value) : 'closed'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function exception(\Throwable $e, int $depth = 0): array
    {
        $result = [
            'class' => \get_class($e),
            'message' => $e->getMessage(),
            'code' => \is_int($e->getCode()) ? $e->getCode() : (string) $e->getCode(),
        ];
        $props = $this->props($e, $depth, self::EXCEPTION_PROPS);
        $props === [] or $result['props'] = $props;
        $previous = $e->getPrevious();
        $result['previous'] = $previous === null || $depth > 16 ? null : $this->exception($previous, $depth + 1);

        return $result;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<string, mixed>
     */
    private function array(array $value, int $depth): array
    {
        $items = [];
        /** @var mixed $item */
        foreach ($value as $key => $item) {
            $items[] = [\is_int($key) ? ['type' => 'int', 'value' => $key] : Value::string($key), $this->describe($item, $depth + 1)];
        }

        return ['type' => 'array', 'items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    private function object(object $value, int $depth): array
    {
        if ($value instanceof \UnitEnum) {
            return ['type' => 'enum', 'class' => \get_class($value), 'case' => $value->name];
        }

        isset($this->ids[$value]) or $this->ids[$value] = ++$this->next;

        /** @var int $id */
        $id = $this->ids[$value];
        $key = \spl_object_id($value);
        if (isset($this->path[$key])) {
            return ['type' => 'ref', 'id' => $id];
        }

        $this->path[$key] = true;
        try {
            return match (true) {
                $value instanceof \Closure => $this->closure($value, $id),
                $value instanceof \Generator => $this->generator($value, $id, $depth),
                $value instanceof \Throwable => ['type' => 'object', 'class' => \get_class($value), 'id' => $id, 'exception' => $this->exception($value, $depth)],
                default => $this->plainObject($value, $id, $depth),
            };
        } finally {
            unset($this->path[$key]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function plainObject(object $value, int $id, int $depth): array
    {
        $result = ['type' => 'object', 'class' => \get_class($value), 'id' => $id, 'props' => $this->props($value, $depth)];
        $state = $this->internalState($value, $depth);
        $state === null or $result['state'] = $state;

        return $result;
    }

    /**
     * Properties in declaration order: private ones as `Class::name`, others as `name`; uninitialized
     * typed properties are absent.
     *
     * @param list<string> $skip
     * @return list<array{string, array<string, mixed>}>
     */
    private function props(object $value, int $depth, array $skip = []): array
    {
        $props = [];
        /** @var mixed $prop */
        foreach (\get_mangled_object_vars($value) as $name => $prop) {
            $name = (string) $name;
            if ($name !== '' && $name[0] === "\0") {
                $parts = \explode("\0", $name, 3);
                $class = $parts[1] ?? '';
                $short = $parts[2] ?? '';
                $name = $class === '*' ? $short : "{$class}::{$short}";
            } else {
                $short = $name;
            }

            if ($skip !== [] && \in_array($short, $skip, true) && $this->isInternalProp($value, $short)) {
                continue;
            }

            $props[] = [$name, $this->describe($prop, $depth + 1)];
        }

        return $props;
    }

    private function isInternalProp(object $value, string $name): bool
    {
        try {
            return (new \ReflectionProperty($value, $name))->getDeclaringClass()->isInternal();
        } catch (\ReflectionException) {
            return true;
        }
    }

    /**
     * State that internal classes keep outside properties.
     *
     * @return array<string, mixed>|null
     */
    private function internalState(object $value, int $depth): ?array
    {
        return match (true) {
            $value instanceof \DateTimeInterface => Value::string($value->format('Y-m-d\TH:i:s.uP e')),
            $value instanceof \DateTimeZone => Value::string($value->getName()),
            $value instanceof \DateInterval => Value::string($value->format('%R %yY %mM %dD %hH %iI %sS %fF') . ' days=' . \var_export($value->days, true)),
            $value instanceof \ArrayObject, $value instanceof \ArrayIterator => $this->describe($value->getArrayCopy(), $depth + 1),
            $value instanceof \SplDoublyLinkedList => $this->describe(\iterator_to_array($value, false), $depth + 1),
            $value instanceof \SplFixedArray => $this->describe($value->toArray(), $depth + 1),
            $value instanceof \SplObjectStorage => $this->storage($value, $depth),
            $value instanceof \WeakMap => ['type' => 'int', 'value' => \count($value)],
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function storage(\SplObjectStorage $storage, int $depth): array
    {
        $items = [];
        foreach ($storage as $i => $object) {
            $items[] = [$this->describe($object, $depth + 1), $this->describe($storage[$object], $depth + 1)];
            if ($i >= self::MAX_ITEMS) {
                break;
            }
        }

        return ['type' => 'array', 'items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    private function closure(\Closure $value, int $id): array
    {
        $reflection = new \ReflectionFunction($value);
        $this_ = $reflection->getClosureThis();

        return [
            'type' => 'closure',
            'id' => $id,
            'this' => $this_ === null ? null : \get_class($this_),
            'scope' => $reflection->getClosureScopeClass()?->getName(),
            'params' => $reflection->getNumberOfParameters(),
        ];
    }

    /**
     * A returned generator is iterated (up to a limit): what it yields is its behavior.
     *
     * @return array<string, mixed>
     */
    private function generator(\Generator $value, int $id, int $depth): array
    {
        $result = ['type' => 'generator', 'id' => $id, 'items' => []];
        try {
            $n = 0;
            /** @var mixed $item */
            foreach ($value as $key => $item) {
                $result['items'][] = [$this->describe($key, $depth + 1), $this->describe($item, $depth + 1)];
                if (++$n >= self::MAX_ITEMS) {
                    $result['truncated'] = true;
                    return $result;
                }
            }

            $result['return'] = $this->describe($value->getReturn(), $depth + 1);
        } catch (\Throwable $e) {
            $result['exception'] = $this->exception($e, $depth + 1);
        }

        return $result;
    }
}
