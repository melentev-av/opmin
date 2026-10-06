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
