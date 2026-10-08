<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Box13
{
    public function __construct(public readonly int $v) {}
}

function twice13(?Box13 $box): int
{
    return $box->v + $box->v;
}

?>
