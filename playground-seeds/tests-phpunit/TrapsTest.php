<?php

declare(strict_types=1);

namespace PlaygroundSeeds\Tests;

use PHPUnit\Framework\TestCase;
use PlaygroundSeeds\Traps;
use Symfony\Bridge\PhpUnit\ClockMock;

final class TrapsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        # Declares PlaygroundSeeds\time(): an unqualified time() call resolves to it.
        ClockMock::register(Traps::class);
    }

    protected function tearDown(): void
    {
        ClockMock::withClockMock(false);
    }

    public function testContextKeepsCompactVariables(): void
    {
        self::assertSame(['user' => 'bob', 'id' => 7, 'upper' => 'BOB'], (new Traps())->context('bob', 7));
    }

    public function testCounterKeepsState(): void
    {
        $traps = new Traps();
        $first = $traps->counter();

        self::assertSame($first + 1, $traps->counter());
    }

    public function testArgCount(): void
    {
        self::assertSame(11, (new Traps())->argCount(1));
        self::assertSame(22, (new Traps())->argCount(1, 2));
    }

    public function testHasKeyWithNullValue(): void
    {
        self::assertTrue((new Traps())->hasKey(['a' => null], 'a'));
        self::assertFalse((new Traps())->hasKey(['a' => null], 'b'));
    }

    public function testIsExpiredUsesMockedClock(): void
    {
        ClockMock::withClockMock(1_000);

        self::assertTrue((new Traps())->isExpired(999));
        self::assertFalse((new Traps())->isExpired(1_000));
    }

    public function testMode(): void
    {
        self::assertSame('legacy', (new Traps())->mode('legacy-v1-compat'));
        self::assertSame('modern', (new Traps())->mode('v2'));
    }

    public function testIsZeroIsLoose(): void
    {
        self::assertTrue((new Traps())->isZero('0'));
        self::assertTrue((new Traps())->isZero(0.0));
        self::assertFalse((new Traps())->isZero('a'));
    }
}
