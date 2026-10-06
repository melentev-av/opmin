<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification\Compare;

use Opmin\Module\Verification\Compare\ComparisonPolicy;
use Opmin\Module\Verification\Compare\ResultComparator;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Invariants of the comparator over generated value descriptions.
 */
#[Test]
#[Covers(ResultComparator::class)]
final class ResultComparatorPropertyTest
{
    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function equalityIsReflexiveGenerators(): array
    {
        return ['value' => self::description(), 'tolerance' => Gen::elements([0.0, 1.0e-12, 1.0e-6])];
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function equalityIsSymmetricGenerators(): array
    {
        return ['a' => self::description(), 'b' => self::description(), 'tolerance' => Gen::elements([0.0, 1.0e-12, 0.5])];
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function negativeZeroNeverEqualsZeroGenerators(): array
    {
        return ['tolerance' => Gen::elements([0.0, 1.0e-12, 1.0, 1.0e9])];
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function signOfFloatsIsAlwaysComparedGenerators(): array
    {
        return ['x' => Gen::floatBetween(1.0e-300, 1.0e300), 'tolerance' => Gen::elements([0.0, 1.0e-12, 0.5])];
    }

    /**
     * Any result equals itself, whatever the tolerance: no false alarm of the determinism check.
     *
     * @param array<string, mixed> $value
     */
    #[Property(runs: 300)]
    public function equalityIsReflexive(array $value, float $tolerance): void
    {
        $result = ResultComparatorTest::returned($value);

        Assert::null((new ResultComparator(new ComparisonPolicy(floatTolerance: $tolerance)))->compare($result, $result));
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    #[Property(runs: 300)]
    public function equalityIsSymmetric(array $a, array $b, float $tolerance): void
    {
        $comparator = new ResultComparator(new ComparisonPolicy(floatTolerance: $tolerance));
        $left = ResultComparatorTest::returned($a);
        $right = ResultComparatorTest::returned($b);

        Assert::same($comparator->compare($left, $right) === null, $comparator->compare($right, $left) === null);
    }

    #[Property(runs: 20)]
    public function negativeZeroNeverEqualsZero(float $tolerance): void
    {
        $comparator = new ResultComparator(new ComparisonPolicy(floatTolerance: $tolerance));
        $zero = ResultComparatorTest::returned(['type' => 'float', 'value' => '0.0']);
        $negative = ResultComparatorTest::returned(['type' => 'float', 'value' => '-0.0']);
        $nan = ResultComparatorTest::returned(['type' => 'float', 'value' => 'NAN']);

        Assert::notNull($comparator->compare($zero, $negative));
        Assert::null($comparator->compare($nan, $nan));
        Assert::notNull($comparator->compare($nan, $zero));
    }

    #[Property(runs: 200)]
    public function signOfFloatsIsAlwaysCompared(float $x, float $tolerance): void
    {
        $comparator = new ResultComparator(new ComparisonPolicy(floatTolerance: $tolerance));

        Assert::notNull($comparator->compare(
            ResultComparatorTest::returned(['type' => 'float', 'value' => self::float($x)]),
            ResultComparatorTest::returned(['type' => 'float', 'value' => self::float(-$x)]),
        ));
    }

    private static function description(): ArbitraryInterface
    {
        $leaf = Gen::frequency([
            [1, Gen::constant(['type' => 'null'])],
            [1, Gen::map(Gen::bool(), static fn(bool $b): array => ['type' => 'bool', 'value' => $b])],
            [2, Gen::map(Gen::int(), static fn(int $i): array => ['type' => 'int', 'value' => $i])],
            [2, Gen::map(Gen::frequency([[3, Gen::floatBetween(-1.0e6, 1.0e6)], [1, Gen::floatSpecial()]]), static fn(float $f): array => ['type' => 'float', 'value' => self::float($f)])],
            [2, Gen::map(Gen::string(), static fn(string $s): array => ['type' => 'string', 'value' => $s])],
            [1, Gen::map(Gen::elements(['A', 'B']), static fn(string $c): array => ['type' => 'enum', 'class' => 'App\E', 'case' => $c])],
        ]);

        return Gen::recursive($leaf, static fn(ArbitraryInterface $inner): ArbitraryInterface => Gen::frequency([
            [1, Gen::map(
                Gen::arrayOf(Gen::tuple(Gen::map(Gen::intBetween(0, 3), static fn(int $k): array => ['type' => 'int', 'value' => $k]), $inner), 0, 4),
                static fn(array $items): array => ['type' => 'array', 'items' => $items],
            )],
            [1, Gen::map(
                Gen::tuple(Gen::intBetween(1, 3), Gen::arrayOf(Gen::tuple(Gen::elements(['a', 'b', 'App\A::c']), $inner), 0, 3)),
                static fn(array $object): array => ['type' => 'object', 'class' => 'App\A', 'id' => $object[0], 'props' => $object[1]],
            )],
        ]), 3);
    }

    /**
     * The harness's float encoding (`Opmin\Harness\Value::floatToString()`).
     */
    private static function float(float $f): string
    {
        return match (true) {
            \is_nan($f) => 'NAN',
            \is_infinite($f) => $f > 0 ? 'INF' : '-INF',
            $f === 0.0 => \fdiv(1.0, $f) < 0 ? '-0.0' : '0.0',
            default => \var_export($f, true),
        };
    }
}
