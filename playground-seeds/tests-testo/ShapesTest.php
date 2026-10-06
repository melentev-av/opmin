<?php

declare(strict_types=1);

namespace PlaygroundSeeds\Tests;

use PlaygroundSeeds\Shapes;
use PlaygroundSeeds\Status;
use Testo\Assert;
use Testo\Test;

use function PlaygroundSeeds\clamp;

#[Test]
final class ShapesTest
{
    public function shapes(): void
    {
        $shapes = new Shapes();

        Assert::same($shapes->squares([1, 2, 3]), [1, 4, 9]);
        Assert::same($shapes->greet('bOB'), 'Hello, Bob!');
        Assert::same(Status::Blocked->label(), 'Blocked');
    }

    public function clamp(): void
    {
        Assert::same(clamp(50, 1, 10), 10);
        Assert::same(clamp(5, 1, 10), 5);
    }
}
