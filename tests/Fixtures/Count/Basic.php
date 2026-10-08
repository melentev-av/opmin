<?php

// Fixture for opcode counting (PHP 8.1+): every kind of function-like code the counter must find.
// Opcode numbers in the tests are checked by hand against the dumps in tests/Fixtures/Dumps/.

declare(strict_types=1);

namespace Fixture\Count;

interface Shape
{
    public function area(): float;
}

abstract class Base
{
    abstract public function name(): string;

    public function describe(): string
    {
        return 'base';
    }
}

trait Greets
{
    public function greet(string $who): string
    {
        return \strtoupper($who) . '!';
    }
}

enum Suit: string
{
    case Hearts = 'h';
    case Spades = 's';

    public function label(): string
    {
        return \ucfirst($this->value);
    }
}

final class Service extends Base
{
    use Greets {
        greet as hello;
    }

    public static function total(array $items): int
    {
        return \count($items) + \strlen('abc');
    }

    public function name(): string
    {
        return 'service';
    }

    /** Doc comment and attribute above the method. */
    #[Marker]
    public function map(
        array $xs,
    ): array {
        $double = function (int $x): int {
            return $x * 2;
        };
        $inc = static fn(int $y): int => $y + 1;
        $len = \strlen(...);

        return \array_map($double, \array_map($inc, $xs));
    }

    public function nested(): \Closure
    {
        return static fn() => fn() => function () {
            return 1;
        };
    }

    public function twoOnOneLine(): array { return [fn() => 1, fn() => 2]; }

    public function anonymous(): object
    {
        $first = fn() => 'before';

        return new class extends Base {
            public function name(): string
            {
                return (fn() => 'anon')();
            }
        };
    }

    public function anonymousTwo(): Shape
    {
        return new class implements Shape { public function area(): float { return 1.0; } };
    }

    public function declaresFunction(): void
    {
        function declaredInside(): int
        {
            return 7;
        }
    }
}

function top(int $a): int
{
    if ($a > 1) {
        return $a;
    }

    return 0;
}

function generate(): \Generator
{
    yield 1;
}

function &byRef(array &$a): array
{
    return $a;
}

if (!\function_exists('Fixture\Count\polyfill')) {
    function polyfill(): string
    {
        return 'poly';
    }
}

$main = static function (): int {
    return 2;
};
