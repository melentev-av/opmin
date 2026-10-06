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
}
