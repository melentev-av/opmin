<?php

declare(strict_types=1);

namespace PlaygroundSeeds\Tests;

use PlaygroundSeeds\MagicBag;
use PlaygroundSeeds\Traps;
use Testo\Assert;
use Testo\Test;

#[Test]
final class TrapsTest
{
    public function context(): void
    {
        Assert::same((new Traps())->context('bob', 7), ['user' => 'bob', 'id' => 7, 'upper' => 'BOB']);
    }

    public function hasKeyWithNullValue(): void
    {
        Assert::true((new Traps())->hasKey(['a' => null], 'a'));
    }

    public function isZeroIsLoose(): void
    {
        Assert::true((new Traps())->isZero('0'));
    }

    public function magicHasDoesNotRead(): void
    {
        $bag = new MagicBag();
        $bag->name = null;

        Assert::true($bag->has('name'));
        Assert::same($bag->reads, 0);
    }
}
