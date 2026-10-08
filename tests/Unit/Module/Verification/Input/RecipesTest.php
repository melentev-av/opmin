<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification\Input;

use Opmin\Module\Verification\Input;
use Opmin\Module\Verification\Input\Recipes;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Recipes::class)]
final class RecipesTest
{
    public function encodesFloatsLikeTheHarness(): void
    {
        Assert::same(
            \array_map(Recipes::floatToString(...), [-0.0, 0.0, \NAN, \INF, -\INF, 1.0, 0.1, 1.0e20]),
            ['-0.0', '0.0', 'NAN', 'INF', '-INF', '1.0', '0.1', '1.0E+20'],
        );
        Assert::same(Recipes::floatToString(Recipes::floatFromString('-0.0')), '-0.0');
    }

    public function binaryStringsAreBase64(): void
    {
        Assert::same(Recipes::string("\xff"), ['type' => 'string', 'base64' => '/w==']);
        Assert::same(Recipes::bytes(Recipes::string("\xff")), "\xff");
    }

    public function renumbersIdsInBuildOrderAndKeepsRefs(): void
    {
        $input = new Input(
            args: [['type' => 'ref', 'id' => 7], ['type' => 'mock', 'interface' => 'I', 'id' => 7], ['type' => 'ref', 'id' => 99]],
            receiver: ['type' => 'object', 'class' => 'A', 'via' => 'ctor', 'args' => [['type' => 'callable', 'id' => 3]], 'id' => 7],
        );

        $renumbered = Recipes::renumber($input);

        Assert::same($renumbered->receiver['id'] ?? null, 2);
        Assert::same($renumbered->receiver['args'][0]['id'] ?? null, 1);
        Assert::same($renumbered->args[0], ['type' => 'ref', 'id' => 2]);
        Assert::same($renumbered->args[1]['id'], 3);
        # A ref to nothing built before it cannot be built: it becomes null.
        Assert::same($renumbered->args[2], ['type' => 'null']);
    }

    public function decodesFloatsLikeTheHarness(): void
    {
        Assert::true(\is_nan(Recipes::floatFromString('NAN')));
        Assert::same(Recipes::floatFromString('INF'), \INF);
        Assert::same(Recipes::floatFromString('-INF'), -\INF);
        Assert::same(\fdiv(1.0, Recipes::floatFromString('-0.0')), -\INF);
        Assert::same(Recipes::floatFromString('1.5'), 1.5);
        Assert::same(Recipes::floatFromString('1.0E+20'), 1.0e20);
        Assert::same(Recipes::floatToString(-1.0e300 * 1.0e300), '-INF');
    }

    public function bytesOfAStringWithoutValueAreEmpty(): void
    {
        Assert::same(Recipes::bytes(['type' => 'string']), '');
        Assert::same(Recipes::bytes(['type' => 'string', 'value' => 'ab']), 'ab');
        Assert::same(Recipes::bytes(['type' => 'string', 'base64' => '%%']), '');
    }

    public function renumbersUsesItemsPropsAndMockReturns(): void
    {
        $input = new Input(
            args: [
                Recipes::array([[Recipes::int(0), ['type' => 'object', 'class' => 'B', 'via' => 'props', 'id' => 40, 'props' => ['self' => ['type' => 'ref', 'id' => 40]]]]]),
                ['type' => 'mock', 'interface' => 'I', 'id' => 50, 'returns' => ['now' => [['type' => 'ref', 'id' => 30]], 'log' => []]],
                ['type' => 'ref', 'id' => 50],
                ['type' => 'ref'],
            ],
            uses: ['k' => ['type' => 'callable', 'id' => 30, 'returns' => [Recipes::int(1)]]],
            strict: true,
        );

        $renumbered = Recipes::renumber($input);

        Assert::same($renumbered->uses['k']['id'], 1);
        Assert::same($renumbered->args[0]['items'][0][1]['id'], 2);
        Assert::same($renumbered->args[0]['items'][0][1]['props']['self'], ['type' => 'ref', 'id' => 2]);
        Assert::same($renumbered->args[1]['returns'], ['now' => [['type' => 'ref', 'id' => 1]], 'log' => []]);
        Assert::same($renumbered->args[1]['id'], 3);
        Assert::same($renumbered->args[2], ['type' => 'ref', 'id' => 3]);
        Assert::same($renumbered->args[3], ['type' => 'null']);
        Assert::true($renumbered->strict);
        Assert::same(Recipes::key($renumbered), Recipes::key(Recipes::renumber($renumbered)));
        Assert::notSame(Recipes::key(new Input([Recipes::float(1.0)])), Recipes::key(new Input([Recipes::int(1)])));
    }
}
