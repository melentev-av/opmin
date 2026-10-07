<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Box17
{
    public function __construct(public readonly int $v) {}
}

function sum17(Box17 $b): int
{
    $a = $b->v;
    $b = new Box17(2);
    return $a + $b->v;
}

?>
