<?php

declare(strict_types=1);

namespace PlaygroundSeeds\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PlaygroundSeeds\Obvious;

final class ObviousTest extends TestCase
{
    public static function descriptions(): iterable
    {
        yield 'empty array' => [[], 'empty array'];
        yield 'array' => [[1, 2], 'array of 2'];
        yield 'string' => ['abc', 'string of 3'];
        yield 'int' => [5, 'integer'];
        yield 'null' => [null, 'NULL'];
    }

    public function testSlugify(): void
    {
        self::assertSame('hello-world', (new Obvious())->slugify('  Hello, World!  '));
        self::assertSame('', (new Obvious())->slugify('!!!'));
    }

    #[DataProvider('descriptions')]
    public function testDescribe(mixed $value, string $expected): void
    {
        self::assertSame($expected, (new Obvious())->describe($value));
    }

    public function testPriceOf(): void
    {
        $prices = ['a' => 10, 'b' => 0];

        self::assertSame(10, (new Obvious())->priceOf($prices, 'a'));
        self::assertSame(0, (new Obvious())->priceOf($prices, 'b'));
        self::assertSame(0, (new Obvious())->priceOf($prices, 'missing'));
    }

    public function testSumEven(): void
    {
        self::assertSame(6, (new Obvious())->sumEven([1, 2, 3, 4]));
        self::assertSame(0, (new Obvious())->sumEven([]));
    }
}
