<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification\Input;

use Opmin\Module\Verification\Input\ClassInfoProvider;
use Opmin\Module\Verification\Input\Recipes;
use Opmin\Module\Verification\Input\TypeSpec;
use Opmin\Module\Verification\Input\ValueGenerator;
use Opmin\Module\Verification\Property\Core\CoreRandom;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Objects, nesting limits and internal classes of {@see ValueGenerator}; scalars are in
 * {@see ValueGeneratorTest}.
 */
#[Test]
#[Covers(ValueGenerator::class)]
final class ValueGeneratorObjectsTest
{
    /** @var list<string> Classes the generator asked about. */
    private array $asked = [];

    public function internalClassesHaveFixedRecipes(): void
    {
        $generator = $this->generator();
        $first = static fn(string $class): array => $generator->edges(new TypeSpec(TypeSpec::CLASS_, $class))[0];
        $date = Recipes::string('2024-02-29 13:14:15.123456');

        Assert::same($first('DateTimeInterface'), ['type' => 'object', 'class' => 'DateTimeImmutable', 'via' => 'ctor', 'args' => [$date]]);
        Assert::same($first('\DateTimeImmutable'), ['type' => 'object', 'class' => 'DateTimeImmutable', 'via' => 'ctor', 'args' => [$date]]);
        Assert::same($first('DateTime'), ['type' => 'object', 'class' => 'DateTime', 'via' => 'ctor', 'args' => [$date]]);
        Assert::same($first('DateTimeZone'), ['type' => 'object', 'class' => 'DateTimeZone', 'via' => 'ctor', 'args' => [Recipes::string('UTC')]]);
        Assert::same($first('DateInterval'), ['type' => 'object', 'class' => 'DateInterval', 'via' => 'ctor', 'args' => [Recipes::string('P1D')]]);
        Assert::same($first('stdClass'), ['type' => 'object', 'class' => 'stdClass', 'via' => 'props', 'props' => []]);
        Assert::same($first('ArrayObject'), ['type' => 'object', 'class' => 'ArrayObject', 'via' => 'ctor', 'args' => [Recipes::list([Recipes::int(0)])]]);
        Assert::same($first('ArrayIterator'), ['type' => 'object', 'class' => 'ArrayIterator', 'via' => 'ctor', 'args' => [Recipes::list([Recipes::int(0)])]]);
        Assert::same($first('Closure'), ['type' => 'callable', 'returns' => [Recipes::int(0)]]);
        Assert::same($first('Throwable'), ['type' => 'object', 'class' => 'Exception', 'via' => 'ctor', 'args' => [Recipes::string('message'), Recipes::int(0)]]);
        foreach (['Exception', 'RuntimeException', 'LogicException', 'InvalidArgumentException'] as $exception) {
            Assert::same($first($exception), ['type' => 'object', 'class' => $exception, 'via' => 'ctor', 'args' => [Recipes::string('message'), Recipes::int(0)]], $exception);
        }

        Assert::same($first('Stringable'), ['type' => 'mock', 'interface' => 'Stringable', 'returns' => ['__toString' => [Recipes::string('')]]]);
        Assert::same($this->asked, []);
    }

    public function nestingStopsAtTheDepthLimit(): void
    {
        $generator = $this->generator();

        # Node(Node $next): three constructors deep, then null.
        Assert::same($generator->edges(new TypeSpec(TypeSpec::CLASS_, 'App\Node'))[0], self::node(self::node(self::node(Recipes::null()))));
        # Tree with a property of its own type.
        $tree = $generator->edges(new TypeSpec(TypeSpec::CLASS_, 'App\Tree'))[0];
        Assert::same($tree, self::tree(self::tree(self::tree(Recipes::null()))));
        # Lists of lists: the innermost value is null.
        $list = new TypeSpec(TypeSpec::ARRAY, value: new TypeSpec(TypeSpec::ARRAY, value: new TypeSpec(TypeSpec::ARRAY, value: new TypeSpec(TypeSpec::ARRAY, value: TypeSpec::of(TypeSpec::INT)))));
        Assert::same($generator->edges($list)[0], Recipes::list([Recipes::list([Recipes::list([Recipes::list([Recipes::null()])])])]));
        # Shapes take part in the depth too.
        $shape = new TypeSpec(TypeSpec::ARRAY, shape: ['a' => [new TypeSpec(TypeSpec::ARRAY, shape: ['b' => [new TypeSpec(TypeSpec::ARRAY, shape: ['c' => [new TypeSpec(TypeSpec::CLASS_, 'App\Node'), false]]), false]]), false]]);
        Assert::same($generator->edges($shape)[0], Recipes::array([[Recipes::string('a'), Recipes::array([[Recipes::string('b'), Recipes::array([[Recipes::string('c'), Recipes::null()]])]])]]));
        # Mixed values at the limit have no arrays.
        Assert::same(\array_column($generator->edges(TypeSpec::mixed(), 3), 'type'), ['null', 'int', 'string', 'bool', 'int', 'string', 'string', 'int', 'float', 'float', 'bool', 'float', 'string', 'float', 'string']);
        Assert::same(\count($generator->edges(TypeSpec::mixed(), 2)), 18);
    }

    public function randomNestingStopsAtTheDepthLimit(): void
    {
        $generator = $this->generator();
        $random = new CoreRandom(new Random(5));
        $depth = static function (array $recipe) use (&$depth): int {
            $inner = [...($recipe['args'] ?? []), ...\array_values($recipe['props'] ?? [])];

            return $inner === [] ? 0 : 1 + \max(\array_map($depth, $inner));
        };

        for ($i = 0; $i < 50; ++$i) {
            $generator->beginInput();
            $recipe = $generator->random(new TypeSpec(TypeSpec::CLASS_, 'App\Node'), $random);
            Assert::true($recipe['type'] === 'ref' || $depth($recipe) <= 3, (string) \json_encode($recipe));
        }
    }

    public function classKindsDecideHowObjectsAreBuilt(): void
    {
        $generator = $this->generator();
        $edges = static fn(string $class): array => $generator->edges(new TypeSpec(TypeSpec::CLASS_, $class));

        Assert::same($edges('App\Empty'), [Recipes::null()]);
        Assert::same($edges('App\Shapeless'), [Recipes::null()]);
        Assert::same($edges('App\Sealed'), [['type' => 'object', 'class' => 'App\Sealed', 'via' => 'props', 'props' => []]]);
        Assert::same($edges('App\Factory'), [['type' => 'object', 'class' => 'App\Factory', 'via' => 'props', 'props' => []]]);
        Assert::same($edges('App\Plain'), [
            ['type' => 'object', 'class' => 'App\Plain', 'via' => 'ctor', 'args' => []],
            ['type' => 'object', 'class' => 'App\Plain', 'via' => 'props', 'props' => []],
        ]);
        Assert::same($edges('App\Gone'), [Recipes::null()]);
        Assert::same($edges('App\Unknown'), [Recipes::null()]);
    }

    public function constructorArgumentsAndPropertiesUseTheirTypes(): void
    {
        $generator = $this->generator();

        Assert::same($generator->edges(new TypeSpec(TypeSpec::CLASS_, 'App\Cart')), [
            ['type' => 'object', 'class' => 'App\Cart', 'via' => 'ctor', 'args' => [Recipes::int(1), Recipes::null(), self::node(self::node(Recipes::null())), Recipes::null()]],
            ['type' => 'object', 'class' => 'App\Cart', 'via' => 'props', 'props' => [
                'count' => Recipes::int(1),
                'App\Base::id' => Recipes::int(0),
                'other' => Recipes::null(),
            ]],
        ]);
        # The class is asked about without a leading backslash, once.
        $generator->edges(new TypeSpec(TypeSpec::CLASS_, '\APP\CART'));
        Assert::same(\array_count_values($this->asked)['App\Cart'] ?? 0, 1);
        Assert::same(\array_count_values($this->asked)['APP\CART'] ?? 0, 0);
    }

    public function randomConstructorsKeepRequiredArguments(): void
    {
        $generator = $this->generator();
        $random = new CoreRandom(new Random(3));
        $sizes = [];
        for ($i = 0; $i < 60; ++$i) {
            $generator->beginInput();
            $recipe = $generator->random(new TypeSpec(TypeSpec::CLASS_, 'App\Cart'), $random);
            ($recipe['via'] ?? null) === 'ctor' and $sizes[\count($recipe['args'])] = true;
        }

        \ksort($sizes);
        # count, note (no `optional` key: required), next; `extra` is optional.
        Assert::same(\array_keys($sizes), [3, 4]);
    }

    public function arraysGetTheirKeysAndKeepNullableValues(): void
    {
        $generator = $this->generator();
        $intKeys = $generator->edges(new TypeSpec(TypeSpec::ARRAY, key: TypeSpec::of(TypeSpec::INT), value: TypeSpec::of(TypeSpec::STRING)));
        $stringKeys = $generator->edges(new TypeSpec(TypeSpec::ARRAY, key: TypeSpec::of(TypeSpec::STRING), value: TypeSpec::of(TypeSpec::STRING)));

        Assert::same($intKeys[3], Recipes::array([[Recipes::int(5), Recipes::string('')]]));
        Assert::same($stringKeys[3], Recipes::array([[Recipes::string('key'), Recipes::string('')]]));
        Assert::same(\count($generator->edges(new TypeSpec(TypeSpec::ARRAY, value: TypeSpec::nullable(TypeSpec::of(TypeSpec::INT))))), 5);
    }

    public function unionsInterleaveTwelveValuesOfEveryMember(): void
    {
        $union = TypeSpec::union([TypeSpec::of(TypeSpec::INT), TypeSpec::of(TypeSpec::STRING)]);

        $edges = $this->generator()->edges($union);

        Assert::same(\count($edges), 24);
        Assert::same(\array_column(\array_slice($edges, 0, 4), 'type'), ['int', 'string', 'int', 'string']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function node(array $next): array
    {
        return ['type' => 'object', 'class' => 'App\Node', 'via' => 'ctor', 'args' => [$next]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function tree(array $child): array
    {
        return ['type' => 'object', 'class' => 'App\Tree', 'via' => 'props', 'props' => ['child' => $child]];
    }

    private function generator(): ValueGenerator
    {
        $this->asked = [];
        $asked = &$this->asked;

        return new ValueGenerator(new class($asked) implements ClassInfoProvider {
            /**
             * @param list<string> $asked
             */
            public function __construct(private array &$asked) {}

            public function info(string $class): ?array
            {
                $this->asked[] = $class;
                $int = ['name' => 'int', 'builtin' => true, 'nullable' => false];
                $class = \strtolower($class) === 'app\cart' ? 'App\Cart' : $class;

                return match ($class) {
                    'App\Node' => ['exists' => true, 'name' => $class, 'kind' => 'class', 'instantiable' => true, 'props' => [], 'constructor' => [
                        'params' => [['name' => 'next', 'type' => ['name' => 'App\Node', 'builtin' => false, 'nullable' => false], 'optional' => false]],
                        'doc' => null,
                        'visibility' => 'public',
                    ]],
                    'App\Tree' => ['exists' => true, 'name' => $class, 'kind' => 'class', 'instantiable' => false, 'constructor' => ['params' => [], 'visibility' => 'private'], 'props' => [
                        ['name' => 'child', 'class' => 'App\Tree', 'type' => ['name' => 'App\Tree', 'builtin' => false, 'nullable' => false], 'doc' => null],
                    ]],
                    'App\Empty' => ['exists' => true, 'name' => $class, 'kind' => 'enum', 'cases' => []],
                    'App\Shapeless' => ['exists' => true, 'name' => $class, 'kind' => 'enum'],
                    'App\Sealed' => ['exists' => true, 'name' => $class, 'kind' => 'abstract', 'final' => true, 'props' => []],
                    'App\Factory' => ['exists' => true, 'name' => $class, 'kind' => 'class', 'instantiable' => false, 'props' => [], 'constructor' => ['params' => [], 'visibility' => 'protected']],
                    'App\Plain' => ['exists' => true, 'name' => $class, 'kind' => 'class', 'instantiable' => true, 'props' => [], 'constructor' => null],
                    'App\Gone' => ['name' => $class],
                    'App\Cart' => ['exists' => true, 'name' => 'App\Cart', 'kind' => 'class', 'instantiable' => true,
                        'props' => [
                            ['name' => 'count', 'class' => 'App\Cart', 'type' => $int, 'doc' => '/** @var positive-int */'],
                            ['name' => 'id', 'class' => 'App\Base', 'type' => $int, 'doc' => null],
                            ['name' => '', 'class' => 'App\Cart', 'type' => $int, 'doc' => null],
                            ['name' => 'other', 'type' => null, 'doc' => '/** @var null */'],
                        ],
                        'constructor' => [
                            'params' => [
                                ['name' => 'count', 'type' => $int],
                                ['name' => 'note', 'type' => null],
                                ['name' => 'next', 'type' => null],
                                ['name' => 'extra', 'type' => null, 'optional' => true],
                                ['name' => 'rest', 'type' => $int, 'variadic' => true],
                            ],
                            'doc' => "/**\n * @param positive-int \$count\n * @param null \$note\n * @param Node \$next\n * @param null \$extra\n */",
                            'visibility' => 'public',
                        ],
                    ],
                    default => null,
                };
            }
        });
    }
}
