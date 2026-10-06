<?php

declare(strict_types=1);

namespace PlaygroundSeeds\Tests;

use PHPUnit\Framework\TestCase;
use PlaygroundSeeds\MagicBag;

final class MagicBagTest extends TestCase
{
    public function testHasDoesNotReadTheValue(): void
    {
        $bag = new MagicBag();
        $bag->name = null;

        self::assertTrue($bag->has('name'));
        self::assertFalse($bag->has('other'));
        self::assertSame(0, $bag->reads);
    }
}
