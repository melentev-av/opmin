<?php

declare(strict_types=1);

namespace PlaygroundSeeds\Tests;

use PlaygroundSeeds\Obvious;
use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
final class ObviousTest
{
    public static function descriptions(): iterable
    {
        yield 'empty array' => [[], 'empty array'];
        yield 'array' => [[1, 2], 'array of 2'];
        yield 'string' => ['abc', 'string of 3'];
        yield 'int' => [5, 'integer'];
    }

    public function slugify(): void
    {
        Assert::same((new Obvious())->slugify('  Hello, World!  '), 'hello-world');
    }

    #[DataProvider('descriptions')]
    public function describe(mixed $value, string $expected): void
    {
        Assert::same((new Obvious())->describe($value), $expected);
    }

    public function priceOf(): void
    {
        Assert::same((new Obvious())->priceOf(['a' => 10], 'a'), 10);
        Assert::same((new Obvious())->priceOf(['a' => 10], 'b'), 0);
    }

    public function sumEven(): void
    {
        Assert::same((new Obvious())->sumEven([1, 2, 3, 4]), 6);
    }
}
