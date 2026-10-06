<?php

declare(strict_types=1);

namespace PlaygroundSeeds\Tests;

use PHPUnit\Framework\TestCase;
use PlaygroundSeeds\Shapes;
use PlaygroundSeeds\Status;

final class ShapesTest extends TestCase
{
    public function testShapes(): void
    {
        $shapes = new Shapes();
        $counter = $shapes->counter();

        self::assertSame([1, 4, 9], $shapes->squares([1, 2, 3]));
        self::assertSame([1, 3], $shapes->lengths(['a', 'abc']));
        self::assertSame('Hello, Bob!', $shapes->greet('bOB'));
        self::assertSame(1, $counter->next());
        self::assertSame(2, $counter->next());
    }

    public function testStatus(): void
    {
        self::assertSame('Active', Status::Active->label());
        self::assertTrue(Status::Active->isActive());
        self::assertFalse(Status::Blocked->isActive());
    }
}
