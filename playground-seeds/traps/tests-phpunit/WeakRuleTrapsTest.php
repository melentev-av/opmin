<?php

declare(strict_types=1);

namespace PlaygroundSeeds\Tests;

use PHPUnit\Framework\TestCase;
use PlaygroundSeeds\BackRefHolder;
use PlaygroundSeeds\CountingMagic;
use PlaygroundSeeds\CountingOffsets;
use PlaygroundSeeds\LazyEntity;
use PlaygroundSeeds\ReferenceWrite;
use PlaygroundSeeds\RuleTraps;
use PlaygroundSeeds\ShrinkingCountable;

/**
 * Weak on purpose: the traps of opmin's rules pass these tests on wrong rewrites too.
 */
final class WeakRuleTrapsTest extends TestCase
{
    public function testRunStartsWithOne(): void
    {
        self::assertSame(1, (new BackRefHolder())->run()[0]);              # misses the cleared property
    }

    public function testTwiceIsPositive(): void
    {
        self::assertGreaterThan(0, (new CountingMagic())->twice());        # misses the counting __get
    }

    public function testReferenceIsPositive(): void
    {
        self::assertGreaterThan(0, (new ReferenceWrite())->run());         # misses the write by reference
    }

    public function testGreetingHasComma(): void
    {
        self::assertStringContainsString(', ', (new LazyEntity())->greet()); # misses the proxy
    }

    public function testPairOfPresentKey(): void
    {
        self::assertSame([1, 1], (new RuleTraps())->pair(['k' => 1]));     # misses the warnings
    }

    public function testOffsetsArePositive(): void
    {
        self::assertGreaterThan(0, (new RuleTraps())->offsets(new CountingOffsets()));
    }

    public function testGrowWithoutOnes(): void
    {
        self::assertSame(2, (new RuleTraps())->grow([5, 6]));              # misses the appended items
    }

    public function testShrinkIsPositive(): void
    {
        self::assertGreaterThan(0, (new RuleTraps())->shrink(new ShrinkingCountable()));
    }
}
