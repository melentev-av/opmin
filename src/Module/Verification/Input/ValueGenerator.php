<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Input;

use Opmin\Module\Verification\Property\RandomSource;

/**
 * Recipes of values of a {@see TypeSpec}: boundary values (phase 1), literals of the body (phase 2),
 * random values, and values outside the type that the caller's coercion may let in (`'5'` for `int`).
 *
 * Objects are built through the constructor or through properties, interfaces and abstract classes
 * become mocks, `Closure`/`callable` a recorded callable. Ids are renumbered per input later
 * ({@see Recipes::renumber()}); a random object sometimes is a ref to an earlier one of the same
 * class, so identity between arguments is exercised.
 *
 * @psalm-type Recipe = array<string, mixed>
 * @internal
 */
final class ValueGenerator
{
    private const MAX_DEPTH = 3;
    private const STRINGS = [
        '', '0', '1', 'a', ' ', 'abc', 'Hello, World!', '-1', '1.5', '1e3', ' 1', '0x1A', 'null', 'true', 'false',
        "a\nb", "\t", 'Ünïcödé', 'Привет', '日本語', '😀', "a\0b", '<b>&amp;</b>', '%s', '\\', '"\'', 'a,b;c',
    ];
    private const INTS = [0, 1, -1, 2, 10, -10, 100, 255, 256, 1000, 65535, 2147483647, -2147483648, \PHP_INT_MAX, \PHP_INT_MIN];
    private const FLOATS = [0.0, -0.0, 1.0, -1.0, 0.5, -0.5, 0.1, 1.5, 100.25, 1.0e15, 1.0e-10, \PHP_FLOAT_EPSILON, \INF, -\INF, \NAN];

    /** @var array<string, array<string, mixed>|null> */
    private array $classes = [];

    /** @var array<string, list<int>> Ids of objects generated per class in the current input. */
    private array $objects = [];

    private int $nextId = 0;

    public function __construct(
        private readonly ClassInfoProvider $info,
        private readonly LiteralPool $literals = new LiteralPool(),
    ) {}

    /**
     * Forgets objects of the previous input (refs point only within one input).
     */
    public function beginInput(): void
    {
        $this->objects = [];
        $this->nextId = 0;
    }

    /**
     * Boundary values of the type, the most typical first.
     *
     * @return list<Recipe>
     */
    public function edges(TypeSpec $type, int $depth = 0): array
    {
        return match ($type->kind) {
            TypeSpec::INT => $this->intEdges($type),
            TypeSpec::FLOAT => \array_map(Recipes::float(...), self::FLOATS),
            TypeSpec::STRING => $this->stringEdges($type),
            TypeSpec::BOOL => [Recipes::bool(false), Recipes::bool(true)],
            TypeSpec::NULL => [Recipes::null()],
            TypeSpec::LITERAL => $type->values,
            TypeSpec::ARRAY, TypeSpec::ITERABLE => $this->arrayEdges($type, $depth),
            TypeSpec::CLASS_ => $this->classEdges((string) $type->class, $depth),
            TypeSpec::OBJECT => [['type' => 'object', 'class' => 'stdClass', 'via' => 'props', 'props' => []]],
            TypeSpec::CALLABLE => [['type' => 'callable', 'returns' => []], ['type' => 'callable', 'returns' => [Recipes::int(1)]]],
            TypeSpec::UNION => $this->unionEdges($type, $depth),
            default => $this->mixedEdges($depth),
        };
    }

    /**
     * Values outside the type that a non-strict caller may still pass: they must behave the same in
     * both versions, `TypeError` included (brief, «Изменение сигнатур», rule 4).
     *
     * @return list<Recipe>
     */
    public function offType(TypeSpec $type): array
    {
        $kinds = $type->kind === TypeSpec::UNION ? \array_map(static fn(TypeSpec $m): string => $m->kind, $type->members) : [$type->kind];
        $result = [];
        \in_array(TypeSpec::INT, $kinds, true) && !\in_array(TypeSpec::STRING, $kinds, true) and \array_push($result, Recipes::string('5'), Recipes::string('5.5'), Recipes::string(' 5'), Recipes::string('5 apples'), Recipes::string('abc'));
        \in_array(TypeSpec::INT, $kinds, true) && !\in_array(TypeSpec::FLOAT, $kinds, true) and \array_push($result, Recipes::float(5.0), Recipes::float(5.5));
        \in_array(TypeSpec::STRING, $kinds, true) && !\in_array(TypeSpec::INT, $kinds, true) and \array_push($result, Recipes::int(5), Recipes::float(1.5));
        \in_array(TypeSpec::FLOAT, $kinds, true) && !\in_array(TypeSpec::STRING, $kinds, true) and $result[] = Recipes::string('1.5');
        \in_array(TypeSpec::FLOAT, $kinds, true) && !\in_array(TypeSpec::INT, $kinds, true) and $result[] = Recipes::int(3);
        if (\array_intersect($kinds, [TypeSpec::INT, TypeSpec::FLOAT, TypeSpec::STRING]) !== [] && !\in_array(TypeSpec::BOOL, $kinds, true)) {
            \array_push($result, Recipes::bool(true), Recipes::bool(false));
        }

        \in_array(TypeSpec::BOOL, $kinds, true) && \count($kinds) === 1 and \array_push($result, Recipes::int(0), Recipes::int(1), Recipes::string(''), Recipes::string('a'));
        $type->allowsNull() || \in_array(TypeSpec::MIXED, $kinds, true) or $result[] = Recipes::null();

        return $result;
    }

    /**
     * Literals of the body that fit the type; for arrays — arrays with the body's keys.
     *
     * @return list<Recipe>
     */
    public function literals(TypeSpec $type): array
    {
        $result = $this->literals->recipesFor($type);
        $arrays = \in_array($type->kind, [TypeSpec::ARRAY, TypeSpec::ITERABLE, TypeSpec::MIXED], true)
            || ($type->kind === TypeSpec::UNION && \array_filter($type->members, static fn(TypeSpec $m): bool => $m->kind === TypeSpec::ARRAY) !== []);
        $keys = $this->literals->keyCandidates();
        if ($arrays && $keys !== []) {
            foreach ($keys as $key) {
                $k = \is_int($key) ? Recipes::int($key) : Recipes::string($key);
                foreach ([Recipes::null(), Recipes::string(''), Recipes::int(0), Recipes::string('x'), Recipes::bool(false)] as $value) {
                    $result[] = Recipes::array([[$k, $value]]);
                }
            }

            $all = [];
            foreach ($keys as $key) {
                $all[] = [\is_int($key) ? Recipes::int($key) : Recipes::string($key), Recipes::string('v')];
            }

            $result[] = Recipes::array($all);
        }

        return $result;
    }

    /**
     * @return Recipe
     */
    public function random(TypeSpec $type, RandomSource $random, int $depth = 0): array
    {
        # A literal of the body now and then: cheap and finds most magic branches.
        if ($type->scalarKinds() !== [] && $random->int(1, 4) === 1) {
            $literals = $this->literals->recipesFor($type);
            if ($literals !== []) {
                return $literals[$random->int(0, \count($literals) - 1)];
            }
        }

        return match ($type->kind) {
            TypeSpec::INT => Recipes::int($this->randomInt($type, $random)),
            TypeSpec::FLOAT => Recipes::float($this->randomFloat($random)),
            TypeSpec::STRING => Recipes::string($this->randomString($type, $random)),
            TypeSpec::BOOL => Recipes::bool($random->int(0, 1) === 1),
            TypeSpec::NULL => Recipes::null(),
            TypeSpec::LITERAL => $type->values === [] ? Recipes::null() : $type->values[$random->int(0, \count($type->values) - 1)],
            TypeSpec::ARRAY, TypeSpec::ITERABLE => $this->randomArray($type, $random, $depth),
            TypeSpec::CLASS_ => $this->randomObject((string) $type->class, $random, $depth),
            TypeSpec::OBJECT => ['type' => 'object', 'class' => 'stdClass', 'via' => 'props', 'props' => $depth >= self::MAX_DEPTH ? [] : ['a' => $this->random(TypeSpec::mixed(), $random, $depth + 1)]],
            TypeSpec::CALLABLE => ['type' => 'callable', 'returns' => [$this->random(TypeSpec::mixed(), $random, self::MAX_DEPTH)]],
            TypeSpec::UNION => $type->members === [] ? Recipes::null() : $this->random($type->members[$random->int(0, \count($type->members) - 1)], $random, $depth),
            default => $this->randomMixed($random, $depth),
        };
    }

    private static function inRange(TypeSpec $type, int $value): bool
    {
        return ($type->min === null || $value >= $type->min) && ($type->max === null || $value <= $type->max);
    }

    /**
     * @return list<Recipe>
     */
    private function intEdges(TypeSpec $type): array
    {
        $values = self::INTS;
        $type->min === null or \array_push($values, $type->min, $type->min + 1, $type->min - 1);
        $type->max === null or \array_push($values, $type->max, $type->max - 1, $type->max + 1);
        $values = \array_values(\array_unique($values));
        # In-range values first: they reach the body, out-of-range ones test the guards.
        \usort($values, static fn(int $a, int $b): int => [!self::inRange($type, $a), \abs($a) > 1000, \abs($a)] <=> [!self::inRange($type, $b), \abs($b) > 1000, \abs($b)]);

        return \array_map(Recipes::int(...), $values);
    }

    /**
     * @return list<Recipe>
     */
    private function stringEdges(TypeSpec $type): array
    {
        $values = $type->numeric ? ['0', '1', '-1', '1.5', '1e3', '0.0', ' 1', '00', '9223372036854775808'] : self::STRINGS;
        $values[] = \str_repeat('x', 300);
        $type->nonEmpty and $values = [...\array_values(\array_filter($values, static fn(string $s): bool => $s !== '')), ''];

        return \array_map(Recipes::string(...), $values);
    }

    /**
     * @return list<Recipe>
     */
    private function arrayEdges(TypeSpec $type, int $depth): array
    {
        if ($type->shape !== []) {
            $full = $required = [];
            foreach ($type->shape as $key => [$valueType, $optional]) {
                $k = \is_int($key) ? Recipes::int($key) : Recipes::string($key);
                $value = $this->edges($valueType, $depth + 1)[0] ?? Recipes::null();
                $full[] = [$k, $value];
                $optional or $required[] = [$k, $value];
            }

            return [Recipes::array($full), Recipes::array($required), Recipes::array([])];
        }

        $value = $type->value ?? TypeSpec::mixed();
        $values = $depth >= self::MAX_DEPTH ? [Recipes::null()] : \array_slice($this->edges($value, $depth + 1), 0, 4);
        $first = $values[0] ?? Recipes::null();
        $result = [
            Recipes::list([$first]),
            Recipes::list($values),
            Recipes::array([]),
        ];
        if (!$type->list) {
            $key = $type->key !== null && $type->key->kind === TypeSpec::INT ? Recipes::int(5) : Recipes::string('key');
            $result[] = Recipes::array([[$key, $first]]);
            $result[] = Recipes::array([[Recipes::int(1), $first], [Recipes::int(0), $values[1] ?? $first]]);
            $value->allowsNull() or $result[] = Recipes::array([[Recipes::string('k'), Recipes::null()]]);
        }

        if ($type->nonEmpty) {
            $result = [...\array_values(\array_filter($result, static fn(array $r): bool => $r['items'] !== [])), Recipes::array([])];
        }

        return $result;
    }

    /**
     * @return list<Recipe>
     */
    private function unionEdges(TypeSpec $type, int $depth): array
    {
        $lists = \array_map(fn(TypeSpec $m): array => $this->edges($m, $depth), $type->members);
        $result = [];
        # Interleave: the first value of every member first.
        for ($i = 0; $lists !== [] && $i < 12; ++$i) {
            foreach ($lists as $list) {
                isset($list[$i]) and $result[] = $list[$i];
            }
        }

        return $result;
    }

    /**
     * @return list<Recipe>
     */
    private function mixedEdges(int $depth): array
    {
        $result = [
            Recipes::null(), Recipes::int(0), Recipes::string(''), Recipes::bool(false), Recipes::int(1), Recipes::string('0'),
            Recipes::string('a'), Recipes::int(-1), Recipes::float(0.0), Recipes::float(1.5), Recipes::bool(true), Recipes::float(-0.0),
            Recipes::string('1'), Recipes::float(\NAN), Recipes::string('Привет'),
        ];
        $depth < self::MAX_DEPTH and \array_push($result, Recipes::array([]), Recipes::list([Recipes::int(1)]), Recipes::array([[Recipes::string('a'), Recipes::null()]]));

        return $result;
    }

    /**
     * @return list<Recipe>
     */
    private function classEdges(string $class, int $depth): array
    {
        $special = $this->internalObject($class, 0);
        if ($special !== null) {
            return $special;
        }

        $info = $this->classInfo($class);
        if ($info === null || $depth >= self::MAX_DEPTH) {
            return [Recipes::null()];
        }

        $kind = (string) ($info['kind'] ?? 'class');
        if ($kind === 'enum') {
            /** @var list<string> $cases */
            $cases = \is_array($info['cases'] ?? null) ? $info['cases'] : [];

            return \array_map(static fn(string $case): array => ['type' => 'enum', 'class' => $class, 'case' => $case], $cases) ?: [Recipes::null()];
        }

        if ($kind === 'interface' || ($kind === 'abstract' && ($info['final'] ?? false) !== true)) {
            return [['type' => 'mock', 'interface' => $class, 'returns' => []]];
        }

        if ($kind !== 'class' || ($info['instantiable'] ?? false) !== true && $info['constructor'] !== null) {
            return [$this->props($class, $info, null, $depth)];
        }

        $result = [];
        $ctor = $this->construct($class, $info, null, $depth);
        $ctor === null or $result[] = $ctor;
        $result[] = $this->props($class, $info, null, $depth);

        return $result;
    }

    /**
     * @return Recipe
     */
    private function randomObject(string $class, RandomSource $random, int $depth): array
    {
        if (isset($this->objects[\strtolower($class)]) && $random->int(1, 6) === 1) {
            $ids = $this->objects[\strtolower($class)];

            return ['type' => 'ref', 'id' => $ids[$random->int(0, \count($ids) - 1)]];
        }

        /** @var int<0, 3> $variant */
        $variant = $random->int(0, 3);
        $special = $this->internalObject($class, $variant);
        if ($special !== null) {
            return $special[0];
        }

        $info = $this->classInfo($class);
        if ($info === null || $depth >= self::MAX_DEPTH) {
            return Recipes::null();
        }

        $kind = (string) ($info['kind'] ?? 'class');
        if ($kind === 'enum') {
            /** @var list<string> $cases */
            $cases = \is_array($info['cases'] ?? null) ? $info['cases'] : [];

            return $cases === [] ? Recipes::null() : ['type' => 'enum', 'class' => $class, 'case' => $cases[$random->int(0, \count($cases) - 1)]];
        }

        if ($kind === 'interface' || $kind === 'abstract') {
            return $this->remember($class, ['type' => 'mock', 'interface' => $class, 'returns' => $this->mockReturns($info, $random, $depth)]);
        }

        $useCtor = ($info['instantiable'] ?? false) === true && $random->int(1, 3) !== 1;
        $recipe = $useCtor ? $this->construct($class, $info, $random, $depth) : null;

        return $this->remember($class, $recipe ?? $this->props($class, $info, $random, $depth));
    }

    /**
     * @param array<string, mixed> $info
     * @return Recipe|null Null when the constructor is not public.
     */
    private function construct(string $class, array $info, ?RandomSource $random, int $depth): ?array
    {
        /** @var array<string, mixed>|null $ctor */
        $ctor = \is_array($info['constructor'] ?? null) ? $info['constructor'] : null;
        if ($ctor !== null && ($ctor['visibility'] ?? 'public') !== 'public') {
            return null;
        }

        $args = [];
        /** @var list<array<string, mixed>> $params */
        $params = $ctor !== null && \is_array($ctor['params'] ?? null) ? $ctor['params'] : [];
        $docs = $this->parser($class)->params($ctor !== null && \is_string($ctor['doc'] ?? null) ? $ctor['doc'] : null);
        foreach ($params as $param) {
            if (($param['variadic'] ?? false) === true || (($param['optional'] ?? false) === true && $random !== null && $random->int(0, 1) === 0)) {
                break;
            }

            $type = TypeParser::refine($this->parser($class)->native(\is_array($param['type'] ?? null) ? $param['type'] : null), $docs[(string) ($param['name'] ?? '')] ?? null);
            $args[] = $random === null ? ($this->edges($type, $depth + 1)[0] ?? Recipes::null()) : $this->random($type, $random, $depth + 1);
        }

        return ['type' => 'object', 'class' => $class, 'via' => 'ctor', 'args' => $args];
    }

    /**
     * @param array<string, mixed> $info
     * @return Recipe
     */
    private function props(string $class, array $info, ?RandomSource $random, int $depth): array
    {
        $props = [];
        /** @var list<array<string, mixed>> $declared */
        $declared = \is_array($info['props'] ?? null) ? $info['props'] : [];
        foreach ($declared as $prop) {
            $name = (string) ($prop['name'] ?? '');
            $owner = (string) ($prop['class'] ?? $class);
            if ($name === '') {
                continue;
            }

            $type = TypeParser::refine(
                $this->parser($owner)->native(\is_array($prop['type'] ?? null) ? $prop['type'] : null),
                $this->parser($owner)->var(\is_string($prop['doc'] ?? null) ? $prop['doc'] : null),
            );
            $key = \strcasecmp($owner, $class) === 0 ? $name : "{$owner}::{$name}";
            $props[$key] = $random === null ? ($this->edges($type, $depth + 1)[0] ?? Recipes::null()) : $this->random($type, $random, $depth + 1);
        }

        return ['type' => 'object', 'class' => $class, 'via' => 'props', 'props' => $props];
    }

    /**
     * @param array<string, mixed> $info
     * @return array<string, list<Recipe>>
     */
    private function mockReturns(array $info, RandomSource $random, int $depth): array
    {
        $returns = [];
        /** @var list<array<string, mixed>> $methods */
        $methods = \is_array($info['methods'] ?? null) ? $info['methods'] : [];
        $class = (string) ($info['name'] ?? '');
        foreach ($methods as $method) {
            if (($method['abstract'] ?? true) !== true || $random->int(0, 2) === 0) {
                continue;
            }

            /** @var array<string, mixed>|null $return */
            $return = \is_array($method['return'] ?? null) ? $method['return'] : null;
            $type = $this->parser($class)->native($return);
            if ($type->kind === TypeSpec::NULL || \in_array($return['name'] ?? null, ['void', 'never', 'static', 'self'], true)) {
                continue;
            }

            $values = [];
            for ($i = $random->int(1, 2); $i > 0; --$i) {
                $values[] = $this->random($type, $random, $depth + 1);
            }

            $returns[(string) ($method['name'] ?? '')] = $values;
        }

        return $returns;
    }

    /**
     * Objects of internal classes that cannot be described by properties.
     *
     * @param int<0, 3> $variant
     * @return list<Recipe>|null
     */
    private function internalObject(string $class, int $variant): ?array
    {
        $lower = \strtolower(\ltrim($class, '\\'));
        $dates = ['2024-02-29 13:14:15.123456', '1970-01-01 00:00:00', '2038-01-19 03:14:08', '1999-12-31 23:59:59'];
        $date = $dates[$variant];

        return match ($lower) {
            'datetimeinterface', 'datetimeimmutable' => [['type' => 'object', 'class' => 'DateTimeImmutable', 'via' => 'ctor', 'args' => [Recipes::string($date)]]],
            'datetime' => [['type' => 'object', 'class' => 'DateTime', 'via' => 'ctor', 'args' => [Recipes::string($date)]]],
            'datetimezone' => [['type' => 'object', 'class' => 'DateTimeZone', 'via' => 'ctor', 'args' => [Recipes::string(['UTC', 'Europe/Moscow', 'America/New_York', 'Asia/Tokyo'][$variant])]]],
            'dateinterval' => [['type' => 'object', 'class' => 'DateInterval', 'via' => 'ctor', 'args' => [Recipes::string(['P1D', 'PT1H', 'P1Y2M', 'PT0S'][$variant])]]],
            'stdclass' => [['type' => 'object', 'class' => 'stdClass', 'via' => 'props', 'props' => $variant === 0 ? [] : ['a' => Recipes::int($variant)]]],
            'arrayobject', 'arrayiterator' => [['type' => 'object', 'class' => $class, 'via' => 'ctor', 'args' => [Recipes::list(\array_map(Recipes::int(...), \range(0, $variant)))]]],
            'closure' => [['type' => 'callable', 'returns' => [Recipes::int($variant)]]],
            'throwable' => [['type' => 'object', 'class' => 'Exception', 'via' => 'ctor', 'args' => [Recipes::string('message'), Recipes::int($variant)]]],
            'exception', 'runtimeexception', 'logicexception', 'invalidargumentexception' => [['type' => 'object', 'class' => $class, 'via' => 'ctor', 'args' => [Recipes::string('message'), Recipes::int($variant)]]],
            'stringable' => [['type' => 'mock', 'interface' => 'Stringable', 'returns' => ['__toString' => [Recipes::string(['', 'a', 'abc', '0'][$variant])]]]],
            'countable', 'iteratoraggregate', 'traversable', 'iterator' => null,
            default => null,
        };
    }

    /**
     * @param Recipe $recipe
     * @return Recipe
     */
    private function remember(string $class, array $recipe): array
    {
        $id = ++$this->nextId;
        $recipe['id'] = $id;
        $this->objects[\strtolower($class)][] = $id;

        return $recipe;
    }

    private function randomInt(TypeSpec $type, RandomSource $random): int
    {
        $min = $type->min ?? \PHP_INT_MIN;
        $max = $type->max ?? \PHP_INT_MAX;
        $roll = $random->int(1, 10);
        $value = match (true) {
            $roll <= 6 => $random->int(-100, 100),
            $roll <= 9 => $random->int(-1_000_000, 1_000_000),
            default => $random->int(\PHP_INT_MIN, \PHP_INT_MAX),
        };

        # Mostly in range, sometimes the guard is tested.
        if (($value < $min || $value > $max) && $random->int(1, 5) !== 1) {
            $value = $random->int($min, \max($min, \min($max, $min + 1000)));
        }

        return $value;
    }

    private function randomFloat(RandomSource $random): float
    {
        return match ($random->int(1, 6)) {
            1 => self::FLOATS[$random->int(0, \count(self::FLOATS) - 1)],
            2 => (float) $random->int(-100, 100),
            3 => \round(($random->float() - 0.5) * 200.0, 2),
            4 => ($random->float() - 0.5) * 1.0e12,
            5 => $random->float() * 1.0e-6,
            default => ($random->float() - 0.5) * 2000.0,
        };
    }

    private function randomString(TypeSpec $type, RandomSource $random): string
    {
        if ($type->numeric || $random->int(1, 8) === 1) {
            return (string) match ($random->int(1, 3)) {
                1 => $random->int(-1000, 1000),
                2 => \round(($random->float() - 0.5) * 100.0, 2),
                default => $random->int(0, 9),
            };
        }

        if ($random->int(1, 5) === 1) {
            return self::STRINGS[$random->int(0, \count(self::STRINGS) - 1)];
        }

        $alphabet = ['a', 'b', 'c', 'x', 'Z', '0', '1', '9', ' ', '-', '_', '.', ',', '/', ':', '@', 'é', 'ж', '中', "\n"];
        $length = $random->int($type->nonEmpty ? 1 : 0, $random->int(1, 4) === 1 ? 40 : 8);
        $result = '';
        for ($i = 0; $i < $length; ++$i) {
            $result .= $alphabet[$random->int(0, \count($alphabet) - 1)];
        }

        return $result;
    }

    /**
     * @return Recipe
     */
    private function randomArray(TypeSpec $type, RandomSource $random, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return Recipes::array([]);
        }

        if ($type->shape !== []) {
            $items = [];
            foreach ($type->shape as $key => [$valueType, $optional]) {
                if ($optional && $random->int(0, 1) === 0) {
                    continue;
                }

                $items[] = [\is_int($key) ? Recipes::int($key) : Recipes::string($key), $this->random($valueType, $random, $depth + 1)];
            }

            return Recipes::array($items);
        }

        $size = $random->int($type->nonEmpty ? 1 : 0, $random->int(1, 4) === 1 ? 8 : 3);
        $value = $type->value ?? TypeSpec::mixed();
        $list = $type->list || $random->int(0, 1) === 0;
        $items = [];
        $used = [];
        for ($i = 0; $i < $size; ++$i) {
            if ($list) {
                $key = Recipes::int($i);
            } else {
                $key = $type->key !== null ? $this->random($type->key, $random, self::MAX_DEPTH) : $this->randomKey($random);
                \in_array($key['type'] ?? null, ['int', 'string'], true) or $key = $this->randomKey($random);
                $id = (string) \json_encode($key);
                if (isset($used[$id])) {
                    continue;
                }

                $used[$id] = true;
            }

            $items[] = [$key, $this->random($value, $random, $depth + 1)];
        }

        return Recipes::array($items);
    }

    /**
     * @return Recipe
     */
    private function randomKey(RandomSource $random): array
    {
        $keys = $this->literals->keyCandidates();
        if ($keys !== [] && $random->int(0, 1) === 0) {
            $key = $keys[$random->int(0, \count($keys) - 1)];

            return \is_int($key) ? Recipes::int($key) : Recipes::string($key);
        }

        return $random->int(0, 1) === 0
            ? Recipes::int($random->int(-2, 10))
            # Not a decimal integer: PHP would store "1" as the int key 1.
            : Recipes::string(['a', 'b', 'key', 'id', 'name', 'x y', '', '01'][$random->int(0, 7)]);
    }

    /**
     * @return Recipe
     */
    private function randomMixed(RandomSource $random, int $depth): array
    {
        $roll = $random->int(1, 100);

        return match (true) {
            $roll <= 25 => $this->random(TypeSpec::of(TypeSpec::INT), $random, $depth),
            $roll <= 50 => $this->random(TypeSpec::of(TypeSpec::STRING), $random, $depth),
            $roll <= 60 => $this->random(TypeSpec::of(TypeSpec::FLOAT), $random, $depth),
            $roll <= 70 => Recipes::bool($random->int(0, 1) === 1),
            $roll <= 80 => Recipes::null(),
            default => $this->randomArray(TypeSpec::of(TypeSpec::ARRAY), $random, $depth),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function classInfo(string $class): ?array
    {
        $key = \strtolower(\ltrim($class, '\\'));
        if (!\array_key_exists($key, $this->classes)) {
            $info = $this->info->info(\ltrim($class, '\\'));
            $this->classes[$key] = $info !== null && ($info['exists'] ?? false) === true ? $info : null;
        }

        return $this->classes[$key];
    }

    /**
     * Type parser for docblocks of a class: names resolve against the class's namespace.
     */
    private function parser(string $class): TypeParser
    {
        $namespace = \str_contains($class, '\\') ? \substr($class, 0, (int) \strrpos($class, '\\')) : '';

        return new TypeParser(
            static fn(string $name): string => \str_starts_with($name, '\\') || $namespace === '' ? \ltrim($name, '\\') : "{$namespace}\\{$name}",
            $class,
        );
    }
}
