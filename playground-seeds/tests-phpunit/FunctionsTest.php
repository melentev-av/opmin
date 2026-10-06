<?php

declare(strict_types=1);

namespace PlaygroundSeeds\Tests;

use PHPUnit\Framework\TestCase;

use function PlaygroundSeeds\clamp;
use function PlaygroundSeeds\is_blank;

final class FunctionsTest extends TestCase
{
    public function testClamp(): void
    {
        self::assertSame(1, clamp(-5, 1, 10));
        self::assertSame(10, clamp(50, 1, 10));
        self::assertSame(5, clamp(5, 1, 10));
    }

    public function testIsBlank(): void
    {
        self::assertTrue(is_blank(null));
        self::assertTrue(is_blank("  \n"));
        self::assertFalse(is_blank(' x '));
    }
}
