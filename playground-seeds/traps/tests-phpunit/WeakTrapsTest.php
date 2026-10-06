<?php

declare(strict_types=1);

namespace PlaygroundSeeds\Tests;

use PHPUnit\Framework\TestCase;
use PlaygroundSeeds\MagicBag;
use PlaygroundSeeds\Traps;

/**
 * Weak on purpose: only happy paths, so wrong rewrites of the traps stay green here.
 */
final class WeakTrapsTest extends TestCase
{
    public function testContextHasUser(): void
    {
        self::assertSame('bob', (new Traps())->context('bob', 1)['user']);       # misses removed $upper
    }

    public function testHasKeyWithStringValue(): void
    {
        self::assertTrue((new Traps())->hasKey(['a' => 'x'], 'a'));            # misses isset() on null
    }

    public function testIsZeroOnInt(): void
    {
        self::assertTrue((new Traps())->isZero(0));                              # misses == → ===
    }

    public function testModeDefault(): void
    {
        self::assertSame('modern', (new Traps())->mode('v2'));                   # misses the magic branch
    }

    public function testCounterIsPositive(): void
    {
        self::assertGreaterThan(0, (new Traps())->counter());                    # misses lost static state
    }

    public function testMagicHas(): void
    {
        $bag = new MagicBag();
        $bag->name = 'x';

        self::assertTrue($bag->has('name'));                                     # misses __get vs __isset
    }
}
