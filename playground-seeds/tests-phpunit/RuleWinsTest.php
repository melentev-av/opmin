<?php

declare(strict_types=1);

namespace PlaygroundSeeds\Tests;

use PHPUnit\Framework\TestCase;
use PlaygroundSeeds\RuleWins;

final class RuleWinsTest extends TestCase
{
    public function testReport(): void
    {
        self::assertSame(['start', '1', '2', 'end'], (new RuleWins())->report([1, 2]));
    }

    public function testAddress(): void
    {
        self::assertSame('https://a:1/a/https', (new RuleWins())->address('a', 1));
    }

    public function testLineTotal(): void
    {
        self::assertSame(10, (new RuleWins())->lineTotal(['qty' => 2, 'price' => 3]));
        self::assertSame(0, (new RuleWins())->lineTotal([]));
    }

    public function testTotal(): void
    {
        self::assertSame(6, (new RuleWins())->total([1, 2, 3]));
    }
}
