<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification\Input;

use Opmin\Module\Verification\Input;
use Opmin\Module\Verification\Input\RecipeShrinker;
use Opmin\Module\Verification\Input\Recipes;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(RecipeShrinker::class)]
final class RecipeShrinkerTest
{
    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function greedyShrinkingEndsGenerators(): array
    {
        return ['recipe' => self::recipe()];
    }

    /**
     * Every candidate of a recipe, in order: [recipe, candidates].
     *
     * @return iterable<string, array{array<string, mixed>, list<array<string, mixed>>}>
     */
    public static function recipes(): iterable
    {
        $i = Recipes::int(...);
        $s = Recipes::string(...);
        $n = Recipes::null();
        yield 'null' => [$n, []];
        yield 'ref' => [['type' => 'ref', 'id' => 1], []];
        yield 'true' => [Recipes::bool(true), [$n, Recipes::bool(false)]];
        yield 'false' => [Recipes::bool(false), [$n]];
        yield 'zero' => [$i(0), [$n]];
        yield 'int' => [$i(-9), [$n, $i(0), $i(-4), $i(-8)]];
        yield 'one' => [$i(1), [$n, $i(0)]];
        yield 'float zero' => [Recipes::float(0.0), [$n]];
        yield 'negative zero' => [Recipes::float(-0.0), [$n, Recipes::float(0.0)]];
        yield 'NAN' => [Recipes::float(\NAN), [$n, Recipes::float(0.0)]];
        yield 'INF' => [Recipes::float(\INF), [$n, Recipes::float(0.0)]];
        yield 'fraction' => [Recipes::float(5.5), [$n, Recipes::float(0.0), Recipes::float(5.0), Recipes::float(2.75)]];
        yield 'small fraction' => [Recipes::float(0.5), [$n, Recipes::float(0.0)]];
        yield 'whole float' => [Recipes::float(-4.0), [$n, Recipes::float(0.0), Recipes::float(-2.0)]];
        yield 'one float' => [Recipes::float(1.0), [$n, Recipes::float(0.0)]];
        yield 'huge float' => [Recipes::float(1.5e300), [$n, Recipes::float(0.0), Recipes::float(7.5e299)]];
        yield 'empty string' => [$s(''), [$n]];
        yield 'one char' => [$s('a'), [$n, $s('')]];
        yield 'string' => [$s('abcd'), [$n, $s(''), $s('ab'), $s('abc')]];
        yield 'unicode string' => [$s('жa'), [$n, $s(''), $s("\xd0"), $s("\xd0\xb6"), $s('aaa')]];
        yield 'empty array' => [Recipes::array([]), [$n]];
        yield 'array' => [Recipes::list([$i(2), Recipes::bool(true)]), [
            $n,
            Recipes::array([]),
            Recipes::array([[$i(1), Recipes::bool(true)]]),
            Recipes::array([[$i(0), $i(2)]]),
            Recipes::list([$n, Recipes::bool(true)]),
            Recipes::list([$i(0), Recipes::bool(true)]),
            Recipes::list([$i(1), Recipes::bool(true)]),
            Recipes::list([$i(2), $n]),
            Recipes::list([$i(2), Recipes::bool(false)]),
        ]];
        yield 'one-item array' => [Recipes::list([$i(0)]), [$n, Recipes::array([]), Recipes::list([$n])]];
        yield 'object by constructor' => [['type' => 'object', 'class' => 'A', 'via' => 'ctor', 'args' => [$i(1), $s('x')], 'id' => 3], [
            $n,
            ['args' => [$n, $s('x')], 'type' => 'object', 'class' => 'A', 'via' => 'ctor', 'id' => 3],
            ['args' => [$i(0), $s('x')], 'type' => 'object', 'class' => 'A', 'via' => 'ctor', 'id' => 3],
            ['args' => [$i(1), $n], 'type' => 'object', 'class' => 'A', 'via' => 'ctor', 'id' => 3],
            ['args' => [$i(1), $s('')], 'type' => 'object', 'class' => 'A', 'via' => 'ctor', 'id' => 3],
        ]];
        yield 'object by properties' => [['type' => 'object', 'class' => 'A', 'via' => 'props', 'props' => ['a' => $i(1), 'b' => Recipes::bool(true)]], [
            $n,
            ['props' => ['a' => $n, 'b' => Recipes::bool(true)], 'type' => 'object', 'class' => 'A', 'via' => 'props'],
            ['props' => ['a' => $i(0), 'b' => Recipes::bool(true)], 'type' => 'object', 'class' => 'A', 'via' => 'props'],
            ['props' => ['b' => $n, 'a' => $i(1)], 'type' => 'object', 'class' => 'A', 'via' => 'props'],
            ['props' => ['b' => Recipes::bool(false), 'a' => $i(1)], 'type' => 'object', 'class' => 'A', 'via' => 'props'],
        ]];
        yield 'mock' => [['type' => 'mock', 'interface' => 'I', 'returns' => ['a' => [$i(1)], 'b' => [Recipes::bool(true), Recipes::bool(false)]]], [
            $n,
            ['returns' => ['b' => [Recipes::bool(true), Recipes::bool(false)]], 'type' => 'mock', 'interface' => 'I'],
            ['returns' => ['a' => [$i(1)]], 'type' => 'mock', 'interface' => 'I'],
            ['returns' => ['a' => [$n], 'b' => [Recipes::bool(true), Recipes::bool(false)]], 'type' => 'mock', 'interface' => 'I'],
            ['returns' => ['a' => [$i(0)], 'b' => [Recipes::bool(true), Recipes::bool(false)]], 'type' => 'mock', 'interface' => 'I'],
            ['returns' => ['b' => [$n, Recipes::bool(false)], 'a' => [$i(1)]], 'type' => 'mock', 'interface' => 'I'],
            ['returns' => ['b' => [Recipes::bool(false), Recipes::bool(false)], 'a' => [$i(1)]], 'type' => 'mock', 'interface' => 'I'],
            ['returns' => ['b' => [Recipes::bool(true), $n], 'a' => [$i(1)]], 'type' => 'mock', 'interface' => 'I'],
        ]];
        yield 'mock without results' => [['type' => 'mock', 'interface' => 'I', 'returns' => []], [$n]];
        yield 'callable' => [['type' => 'callable', 'returns' => [$i(2), $s('a')]], [
            $n,
            ['returns' => [$i(2)], 'type' => 'callable'],
            ['returns' => [$n, $s('a')], 'type' => 'callable'],
            ['returns' => [$i(0), $s('a')], 'type' => 'callable'],
            ['returns' => [$i(1), $s('a')], 'type' => 'callable'],
            ['returns' => [$i(2), $n], 'type' => 'callable'],
            ['returns' => [$i(2), $s('')], 'type' => 'callable'],
        ]];
        yield 'enum' => [['type' => 'enum', 'class' => 'E', 'case' => 'A'], [$n]];
    }

    /**
     * @param array<string, mixed> $recipe
     * @param list<array<string, mixed>> $expected
     */
    #[DataProvider('recipes')]
    public function candidatesOfARecipe(array $recipe, array $expected): void
    {
        Assert::same(\iterator_to_array(RecipeShrinker::shrink($recipe), false), $expected);
    }

    public function inputCandidatesCoverEveryPart(): void
    {
        $input = new Input(
            [Recipes::int(1)],
            ['type' => 'object', 'class' => 'A', 'via' => 'props', 'props' => ['n' => Recipes::bool(true)]],
            ['k' => Recipes::int(2), 'm' => Recipes::bool(false)],
        );

        $candidates = \array_map(static fn(Input $i): array => $i->toArray(), \iterator_to_array((new RecipeShrinker(1))->candidates($input), false));

        Assert::same(\array_column($candidates, 'args'), [[Recipes::null()], [Recipes::int(0)], [Recipes::int(1)], [Recipes::int(1)], [Recipes::int(1)], [Recipes::int(1)], [Recipes::int(1)], [Recipes::int(1)]]);
        Assert::same($candidates[2]['this']['props'] ?? null, ['n' => Recipes::null()]);
        Assert::same($candidates[3]['this']['props'] ?? null, ['n' => Recipes::bool(false)]);
        Assert::same($candidates[4]['uses'], ['k' => Recipes::null(), 'm' => Recipes::bool(false)]);
        Assert::same($candidates[5]['uses'], ['k' => Recipes::int(0), 'm' => Recipes::bool(false)]);
        Assert::same($candidates[6]['uses'], ['k' => Recipes::int(1), 'm' => Recipes::bool(false)]);
        Assert::same($candidates[7]['uses'], ['m' => Recipes::null(), 'k' => Recipes::int(2)]);
        Assert::same(\count($candidates), 8);
    }

    public function shrinksTowardsSimpleValues(): void
    {
        $shrinker = new RecipeShrinker(1);

        $candidates = \iterator_to_array($shrinker->candidates(new Input([Recipes::int(100), Recipes::string('Привет')], strict: true)), false);

        Assert::same($candidates[0]->strict, false);
        Assert::same($candidates[1]->args, [Recipes::int(100)]);
        Assert::same($candidates[2]->args[0], Recipes::null());
        Assert::same(\array_map(static fn(Input $i): array => $i->args[0], \array_slice($candidates, 3, 3)), [Recipes::int(0), Recipes::int(50), Recipes::int(99)]);
    }

    public function receiverIsNeverNull(): void
    {
        $input = new Input([], ['type' => 'object', 'class' => 'A', 'via' => 'props', 'props' => ['n' => Recipes::int(2)]]);

        foreach ((new RecipeShrinker())->candidates($input) as $candidate) {
            Assert::same($candidate->receiver['type'] ?? null, 'object');
        }
    }

    /**
     * Descending through candidates always ends: every candidate is strictly smaller. The descent
     * takes candidates in turn (first, second, …) to walk different paths than the engine's greedy one.
     *
     * @param array<string, mixed> $recipe
     */
    #[Property(runs: 200)]
    public function greedyShrinkingEnds(array $recipe): void
    {
        $shrinker = new RecipeShrinker();
        $input = new Input([$recipe]);
        $steps = 0;
        $seen = [];
        while (++$steps < 10_000) {
            $key = Recipes::key($input);
            Assert::false(isset($seen[$key]), 'a candidate repeats an earlier input');
            $seen[$key] = true;
            $candidates = \iterator_to_array($shrinker->candidates($input), false);
            if ($candidates === []) {
                break;
            }

            $input = $candidates[$steps % \count($candidates)];
        }

        Assert::true($steps < 10_000);
    }

    private static function recipe(): ArbitraryInterface
    {
        $leaf = Gen::frequency([
            [2, Gen::map(Gen::int(), Recipes::int(...))],
            [2, Gen::map(Gen::frequency([[3, Gen::floatBetween(-1.0e6, 1.0e6)], [1, Gen::floatSpecial()]]), Recipes::float(...))],
            [2, Gen::map(Gen::string(), Recipes::string(...))],
            [1, Gen::map(Gen::bool(), Recipes::bool(...))],
            [1, Gen::constant(Recipes::null())],
        ]);

        return Gen::recursive($leaf, static fn(ArbitraryInterface $inner): ArbitraryInterface => Gen::frequency([
            [1, Gen::map(Gen::arrayOf($inner, 0, 4), Recipes::list(...))],
            [1, Gen::map(Gen::arrayOf($inner, 0, 3), static fn(array $args): array => ['type' => 'object', 'class' => 'A', 'via' => 'ctor', 'args' => $args])],
            [1, Gen::map(Gen::arrayOf($inner, 0, 3), static fn(array $returns): array => ['type' => 'mock', 'interface' => 'I', 'returns' => ['m' => $returns]])],
        ]), 3);
    }
}
