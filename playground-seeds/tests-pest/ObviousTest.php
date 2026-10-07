<?php

declare(strict_types=1);

use PlaygroundSeeds\Obvious;

test('slugify', function () {
    expect((new Obvious())->slugify('  Hello, World!  '))->toBe('hello-world');
});

test('describe', function (mixed $value, string $expected) {
    expect((new Obvious())->describe($value))->toBe($expected);
})->with([
    'empty array' => [[], 'empty array'],
    'array' => [[1, 2], 'array of 2'],
    'string' => ['abc', 'string of 3'],
    'int' => [5, 'integer'],
]);

test('price of', function () {
    expect((new Obvious())->priceOf(['a' => 10], 'a'))->toBe(10)
        ->and((new Obvious())->priceOf(['a' => 10], 'b'))->toBe(0);
});

test('sum of even numbers', function () {
    expect((new Obvious())->sumEven([1, 2, 3, 4]))->toBe(6);
});
