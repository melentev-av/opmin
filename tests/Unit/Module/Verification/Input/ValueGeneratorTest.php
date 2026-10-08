<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification\Input;

use Opmin\Module\Verification\Input\ClassInfoProvider;
use Opmin\Module\Verification\Input\LiteralPool;
use Opmin\Module\Verification\Input\Recipes;
use Opmin\Module\Verification\Input\TypeSpec;
use Opmin\Module\Verification\Input\ValueGenerator;
use Opmin\Module\Verification\Property\Core\CoreRandom;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ValueGenerator::class)]
final class ValueGeneratorTest
{
    /**
     * @return iterable<string, array{TypeSpec, list<array<string, mixed>>}>
     */
    public static function valuesOutsideTheType(): iterable
    {
        $s = Recipes::string(...);
        yield 'int' => [TypeSpec::of(TypeSpec::INT), [$s('5'), $s('5.5'), $s(' 5'), $s('5 apples'), $s('abc'), Recipes::float(5.0), Recipes::float(5.5), Recipes::bool(true), Recipes::bool(false), Recipes::null()]];
        yield 'string' => [TypeSpec::of(TypeSpec::STRING), [Recipes::int(5), Recipes::float(1.5), Recipes::bool(true), Recipes::bool(false), Recipes::null()]];
        yield 'float' => [TypeSpec::of(TypeSpec::FLOAT), [$s('1.5'), Recipes::int(3), Recipes::bool(true), Recipes::bool(false), Recipes::null()]];
        yield 'bool' => [TypeSpec::of(TypeSpec::BOOL), [Recipes::int(0), Recipes::int(1), $s(''), $s('a'), Recipes::null()]];
        yield 'nullable int' => [TypeSpec::nullable(TypeSpec::of(TypeSpec::INT)), [$s('5'), $s('5.5'), $s(' 5'), $s('5 apples'), $s('abc'), Recipes::float(5.0), Recipes::float(5.5), Recipes::bool(true), Recipes::bool(false)]];
        yield 'int|string' => [TypeSpec::union([TypeSpec::of(TypeSpec::INT), TypeSpec::of(TypeSpec::STRING)]), [Recipes::float(5.0), Recipes::float(5.5), Recipes::bool(true), Recipes::bool(false), Recipes::null()]];
        yield 'int|float' => [TypeSpec::union([TypeSpec::of(TypeSpec::INT), TypeSpec::of(TypeSpec::FLOAT)]), [$s('5'), $s('5.5'), $s(' 5'), $s('5 apples'), $s('abc'), $s('1.5'), Recipes::bool(true), Recipes::bool(false), Recipes::null()]];
        yield 'bool|int' => [TypeSpec::union([TypeSpec::of(TypeSpec::BOOL), TypeSpec::of(TypeSpec::INT)]), [$s('5'), $s('5.5'), $s(' 5'), $s('5 apples'), $s('abc'), Recipes::float(5.0), Recipes::float(5.5), Recipes::null()]];
        yield 'mixed' => [TypeSpec::mixed(), []];
        yield 'array' => [TypeSpec::of(TypeSpec::ARRAY), [Recipes::null()]];
    }

    /**
     * @param list<array<string, mixed>> $expected
     */
    #[DataProvider('valuesOutsideTheType')]
    public function offTypeValuesAreWhatACoercingCallerMayPass(TypeSpec $type, array $expected): void
    {
        Assert::same(self::generator()->offType($type), $expected);
    }

    public function intEdgesPutTheRangeFirst(): void
    {
        $values = \array_column(self::generator()->edges(new TypeSpec(TypeSpec::INT, min: 5, max: 10)), 'value');

        Assert::same(\array_slice($values, 0, 4), [5, 6, 9, 10]);
        Assert::same(\array_slice($values, 4, 5), [0, 1, -1, 2, 4]);
        Assert::true(\in_array(11, $values, true));
        Assert::same(\array_slice(\array_column(self::generator()->edges(TypeSpec::of(TypeSpec::INT)), 'value'), 0, 6), [0, 1, -1, 2, 10, -10]);
        Assert::same(\array_slice(\array_column(self::generator()->edges(TypeSpec::of(TypeSpec::INT)), 'value'), -3), [-2147483648, \PHP_INT_MAX, \PHP_INT_MIN]);
    }

    public function stringEdges(): void
    {
        $plain = \array_map(Recipes::bytes(...), self::generator()->edges(TypeSpec::of(TypeSpec::STRING)));
        $nonEmpty = \array_map(Recipes::bytes(...), self::generator()->edges(new TypeSpec(TypeSpec::STRING, nonEmpty: true)));
        $numeric = \array_map(Recipes::bytes(...), self::generator()->edges(new TypeSpec(TypeSpec::STRING, numeric: true)));

        Assert::same(\array_slice($plain, 0, 3), ['', '0', '1']);
        Assert::same(\end($plain), \str_repeat('x', 300));
        Assert::same([$nonEmpty[0], \end($nonEmpty)], ['0', '']);
        Assert::same(\array_slice($numeric, 0, 3), ['0', '1', '-1']);
        Assert::true(\in_array("a\0b", $plain, true));
    }

    public function edgesOfOtherKinds(): void
    {
        $generator = self::generator();

        Assert::same($generator->edges(TypeSpec::of(TypeSpec::BOOL)), [Recipes::bool(false), Recipes::bool(true)]);
        Assert::same($generator->edges(TypeSpec::of(TypeSpec::NULL)), [Recipes::null()]);
        Assert::same(Recipes::floatToString(Recipes::floatFromString((string) $generator->edges(TypeSpec::of(TypeSpec::FLOAT))[1]['value'])), '-0.0');
        Assert::same($generator->edges(new TypeSpec(TypeSpec::LITERAL, values: [Recipes::string('a')])), [Recipes::string('a')]);
        Assert::same($generator->edges(TypeSpec::of(TypeSpec::OBJECT)), [['type' => 'object', 'class' => 'stdClass', 'via' => 'props', 'props' => []]]);
        Assert::same(\array_column($generator->edges(TypeSpec::of(TypeSpec::CALLABLE)), 'returns'), [[], [Recipes::int(1)]]);
        Assert::same(\array_slice($generator->edges(TypeSpec::mixed()), 0, 4), [Recipes::null(), Recipes::int(0), Recipes::string(''), Recipes::bool(false)]);
        # Union: the first value of every member first.
        Assert::same(\array_slice($generator->edges(TypeSpec::union([TypeSpec::of(TypeSpec::INT), TypeSpec::of(TypeSpec::BOOL)])), 0, 4), [Recipes::int(0), Recipes::bool(false), Recipes::int(1), Recipes::bool(true)]);
    }

    public function arrayEdges(): void
    {
        $generator = self::generator();
        $list = $generator->edges(new TypeSpec(TypeSpec::ARRAY, value: TypeSpec::of(TypeSpec::INT), list: true));
        $map = $generator->edges(new TypeSpec(TypeSpec::ARRAY, key: TypeSpec::of(TypeSpec::INT), value: TypeSpec::of(TypeSpec::STRING)));
        $nonEmpty = $generator->edges(new TypeSpec(TypeSpec::ARRAY, value: TypeSpec::of(TypeSpec::INT), nonEmpty: true, list: true));
        $shape = $generator->edges(new TypeSpec(TypeSpec::ARRAY, shape: ['id' => [TypeSpec::of(TypeSpec::INT), false], 'tag' => [TypeSpec::of(TypeSpec::STRING), true]]));

        Assert::same($list, [Recipes::list([Recipes::int(0)]), Recipes::list(\array_map(Recipes::int(...), [0, 1, -1, 2])), Recipes::array([])]);
        Assert::same($map[3], Recipes::array([[Recipes::int(5), Recipes::string('')]]));
        Assert::same($map[4], Recipes::array([[Recipes::int(1), Recipes::string('')], [Recipes::int(0), Recipes::string('0')]]));
        Assert::same($map[5], Recipes::array([[Recipes::string('k'), Recipes::null()]]));
        Assert::same(\count($map), 6);
        Assert::same(\end($nonEmpty), Recipes::array([]));
        Assert::same(\count($nonEmpty), 3);
        Assert::same($shape, [
            Recipes::array([[Recipes::string('id'), Recipes::int(0)], [Recipes::string('tag'), Recipes::string('')]]),
            Recipes::array([[Recipes::string('id'), Recipes::int(0)]]),
            Recipes::array([]),
        ]);
    }

    public function literalsBecomeArrayKeys(): void
    {
        $pool = (new LiteralPool())->addInts([7]);
        $generator = new ValueGenerator(self::classes(), $pool);

        $arrays = $generator->literals(TypeSpec::of(TypeSpec::ARRAY));
        $ints = $generator->literals(TypeSpec::of(TypeSpec::INT));

        Assert::same($arrays[0], Recipes::array([[Recipes::int(7), Recipes::null()]]));
        Assert::same(\end($arrays), Recipes::array([[Recipes::int(7), Recipes::string('v')], [Recipes::int(6), Recipes::string('v')], [Recipes::int(8), Recipes::string('v')]]));
        Assert::same(\count($arrays), 16);
        Assert::same($ints, [Recipes::int(7), Recipes::int(6), Recipes::int(8)]);
        Assert::same($generator->literals(TypeSpec::of(TypeSpec::BOOL)), []);
    }

    public function objectsOfEveryKind(): void
    {
        $generator = self::generator();

        Assert::same($generator->edges(new TypeSpec(TypeSpec::CLASS_, 'App\Status')), [['type' => 'enum', 'class' => 'App\Status', 'case' => 'On'], ['type' => 'enum', 'class' => 'App\Status', 'case' => 'Off']]);
        Assert::same($generator->edges(new TypeSpec(TypeSpec::CLASS_, 'App\Clock')), [['type' => 'mock', 'interface' => 'App\Clock', 'returns' => []]]);
        Assert::same($generator->edges(new TypeSpec(TypeSpec::CLASS_, 'App\Base')), [['type' => 'mock', 'interface' => 'App\Base', 'returns' => []]]);
        Assert::same($generator->edges(new TypeSpec(TypeSpec::CLASS_, 'App\Money')), [
            ['type' => 'object', 'class' => 'App\Money', 'via' => 'ctor', 'args' => [Recipes::int(1), Recipes::string('')]],
            ['type' => 'object', 'class' => 'App\Money', 'via' => 'props', 'props' => ['amount' => Recipes::int(0), 'App\Base::secret' => Recipes::string('')]],
        ]);
        Assert::same($generator->edges(new TypeSpec(TypeSpec::CLASS_, 'App\Hidden')), [['type' => 'object', 'class' => 'App\Hidden', 'via' => 'props', 'props' => []]]);
        Assert::same($generator->edges(new TypeSpec(TypeSpec::CLASS_, 'App\Missing')), [Recipes::null()]);
        Assert::same($generator->edges(new TypeSpec(TypeSpec::CLASS_, 'DateTimeImmutable'))[0]['args'] ?? null, [Recipes::string('2024-02-29 13:14:15.123456')]);
        Assert::same($generator->edges(new TypeSpec(TypeSpec::CLASS_, '\Closure')), [['type' => 'callable', 'returns' => [Recipes::int(0)]]]);
        Assert::same($generator->edges(new TypeSpec(TypeSpec::CLASS_, 'Throwable'))[0]['class'] ?? null, 'Exception');
        Assert::same($generator->edges(new TypeSpec(TypeSpec::CLASS_, 'Countable')), [Recipes::null()]);
    }

    public function randomObjectsReuseEarlierOnesAndStayInTheInput(): void
    {
        $generator = self::generator();
        $random = new CoreRandom(new Random(3));
        $refs = 0;
        $ids = [];
        for ($i = 0; $i < 300; ++$i) {
            $generator->beginInput();
            $first = $generator->random(new TypeSpec(TypeSpec::CLASS_, 'App\Money'), $random);
            $second = $generator->random(new TypeSpec(TypeSpec::CLASS_, 'App\Money'), $random);
            Assert::same($first['id'] ?? null, 1);
            $second['type'] === 'ref' and ++$refs and Assert::same($second['id'], 1);
            $ids[] = $second['id'] ?? null;
        }

        Assert::true($refs > 20 && $refs < 120, "refs: {$refs}");
        Assert::true(\in_array(2, $ids, true));
    }

    public function randomValuesKeepTheirShape(): void
    {
        $generator = self::generator();
        $random = new CoreRandom(new Random(11));
        for ($i = 0; $i < 200; ++$i) {
            $list = $generator->random(new TypeSpec(TypeSpec::ARRAY, value: TypeSpec::of(TypeSpec::INT), nonEmpty: true, list: true), $random);
            $keys = \array_map(static fn(array $item): mixed => $item[0]['value'], $list['items']);
            Assert::same($keys, \range(0, \count($keys) - 1));
            Assert::true(\count($keys) >= 1);

            $map = $generator->random(new TypeSpec(TypeSpec::ARRAY, key: TypeSpec::of(TypeSpec::STRING), value: TypeSpec::of(TypeSpec::BOOL)), $random);
            $mapKeys = \array_map(static fn(array $item): string => \json_encode($item[0]), $map['items']);
            Assert::same(\count($mapKeys), \count(\array_unique($mapKeys)));

            $shape = $generator->random(new TypeSpec(TypeSpec::ARRAY, shape: ['id' => [TypeSpec::of(TypeSpec::INT), false]]), $random);
            Assert::same($shape['items'][0][0], Recipes::string('id'));

            $literal = $generator->random(new TypeSpec(TypeSpec::LITERAL, values: [Recipes::string('a'), Recipes::string('b')]), $random);
            Assert::true(\in_array($literal, [Recipes::string('a'), Recipes::string('b')], true));

            $deep = $generator->random(TypeSpec::of(TypeSpec::ARRAY), $random, depth: 3);
            Assert::same($deep, Recipes::array([]));

            $range = $generator->random(new TypeSpec(TypeSpec::INT, min: 0, max: 9), $random);
            Assert::same($range['type'], 'int');
        }
    }

    public function randomValueOfEveryKind(): void
    {
        $generator = new ValueGenerator(self::classes(), (new LiteralPool())->addInts([123456]));
        $random = new CoreRandom(new Random(21));
        $types = static fn(TypeSpec $type, int $n = 60): array => \array_count_values(\array_map(
            static fn(): string => (string) $generator->random($type, $random)['type'],
            \range(1, $n),
        ));

        Assert::same(\array_keys($types(TypeSpec::of(TypeSpec::FLOAT))), ['float']);
        Assert::same(\array_keys($types(TypeSpec::of(TypeSpec::STRING))), ['string']);
        Assert::same(\array_keys($types(TypeSpec::of(TypeSpec::BOOL))), ['bool']);
        Assert::same(\array_keys($types(TypeSpec::of(TypeSpec::NULL))), ['null']);
        Assert::same(\array_keys($types(TypeSpec::of(TypeSpec::ITERABLE))), ['array']);
        Assert::same(\array_keys($types(TypeSpec::of(TypeSpec::OBJECT))), ['object']);
        Assert::same(\array_keys($types(TypeSpec::of(TypeSpec::CALLABLE))), ['callable']);
        Assert::same($generator->random(TypeSpec::union([]), $random), Recipes::null());
        Assert::same($generator->random(new TypeSpec(TypeSpec::LITERAL), $random), Recipes::null());
        $union = $types(TypeSpec::union([TypeSpec::of(TypeSpec::NULL), TypeSpec::of(TypeSpec::BOOL)]));
        Assert::same([isset($union['null']), isset($union['bool'])], [true, true]);
        $mixed = $types(TypeSpec::mixed(), 300);
        \ksort($mixed);
        Assert::same(\array_keys($mixed), ['array', 'bool', 'float', 'int', 'null', 'string']);
        # One draw in four of a scalar type is a literal of the body.
        $literal = \count(\array_filter(\range(1, 400), static fn(): bool => \in_array(
            $generator->random(TypeSpec::of(TypeSpec::INT), $random)['value'],
            [123455, 123456, 123457],
            true,
        )));
        Assert::true($literal > 60 && $literal < 150, "literals: {$literal}");
        Assert::same($generator->edges(TypeSpec::of(TypeSpec::ITERABLE)), $generator->edges(TypeSpec::of(TypeSpec::ARRAY)));
    }

    public function literalArraysForUnionsWithArrays(): void
    {
        $pool = (new LiteralPool())->addInts([7]);
        $generator = new ValueGenerator(self::classes(), $pool);

        Assert::same(\count($generator->literals(TypeSpec::union([TypeSpec::of(TypeSpec::ARRAY), TypeSpec::of(TypeSpec::NULL)]))), 16);
        Assert::same(\count($generator->literals(TypeSpec::union([TypeSpec::of(TypeSpec::BOOL), TypeSpec::of(TypeSpec::NULL)]))), 0);
        Assert::same(\array_slice($generator->literals(TypeSpec::of(TypeSpec::ARRAY)), 0, 5), [
            Recipes::array([[Recipes::int(7), Recipes::null()]]),
            Recipes::array([[Recipes::int(7), Recipes::string('')]]),
            Recipes::array([[Recipes::int(7), Recipes::int(0)]]),
            Recipes::array([[Recipes::int(7), Recipes::string('x')]]),
            Recipes::array([[Recipes::int(7), Recipes::bool(false)]]),
        ]);
    }

    public function constructorTakesPhpdocTypesAndStopsAtVariadics(): void
    {
        $generator = self::generator();
        $random = new CoreRandom(new Random(5));
        $sizes = [];
        for ($i = 0; $i < 100; ++$i) {
            $generator->beginInput();
            $object = $generator->random(new TypeSpec(TypeSpec::CLASS_, 'App\Money'), $random);
            if (($object['via'] ?? null) === 'ctor') {
                $sizes[\count($object['args'])] = true;
                Assert::true($object['args'][0]['type'] === 'int' || $object['args'][0]['type'] === 'string');
            }
        }

        # amount is required, note optional, tags variadic: 1 or 2 arguments.
        \ksort($sizes);
        Assert::same(\array_keys($sizes), [1, 2]);
    }

    private static function generator(): ValueGenerator
    {
        return new ValueGenerator(self::classes());
    }

    private static function classes(): ClassInfoProvider
    {
        return new class implements ClassInfoProvider {
            public function info(string $class): ?array
            {
                $int = ['name' => 'int', 'builtin' => true, 'nullable' => false];
                $string = ['name' => 'string', 'builtin' => true, 'nullable' => false];

                return match ($class) {
                    'App\Status' => ['exists' => true, 'name' => $class, 'kind' => 'enum', 'cases' => ['On', 'Off']],
                    'App\Clock' => ['exists' => true, 'name' => $class, 'kind' => 'interface', 'methods' => []],
                    'App\Base' => ['exists' => true, 'name' => $class, 'kind' => 'abstract', 'final' => false, 'methods' => []],
                    'App\Hidden' => ['exists' => true, 'name' => $class, 'kind' => 'class', 'instantiable' => false, 'props' => [], 'constructor' => ['params' => [], 'visibility' => 'private']],
                    'App\Money' => ['exists' => true, 'name' => $class, 'kind' => 'class', 'instantiable' => true,
                        'props' => [
                            ['name' => 'amount', 'class' => 'App\Money', 'type' => $int, 'doc' => null],
                            ['name' => 'secret', 'class' => 'App\Base', 'type' => $string, 'doc' => null],
                        ],
                        'constructor' => [
                            'params' => [
                                ['name' => 'amount', 'type' => $int, 'optional' => false],
                                ['name' => 'note', 'type' => $string, 'optional' => true],
                                ['name' => 'tags', 'type' => $string, 'optional' => true, 'variadic' => true],
                            ],
                            'doc' => '/** @param positive-int $amount */',
                            'visibility' => 'public',
                        ],
                    ],
                    'App\Missing' => ['exists' => false, 'name' => $class],
                    default => null,
                };
            }
        };
    }
}
