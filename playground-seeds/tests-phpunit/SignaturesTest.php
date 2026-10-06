<?php

declare(strict_types=1);

namespace PlaygroundSeeds\Tests;

use PHPUnit\Framework\TestCase;
use PlaygroundSeeds\Signatures;

final class SignaturesTest extends TestCase
{
    public function testAddAcceptsNumericStrings(): void
    {
        self::assertSame(5, (new Signatures())->add('2', 3));
        self::assertSame(2.5, (new Signatures())->add(1, 1.5));
    }

    public function testQuadruple(): void
    {
        self::assertSame(12, (new Signatures())->quadruple(3));
        self::assertSame(2.0, (new Signatures())->quadruple(0.5));
    }
}
