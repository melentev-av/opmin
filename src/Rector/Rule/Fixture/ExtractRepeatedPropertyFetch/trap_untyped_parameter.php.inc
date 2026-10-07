<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Box12
{
    public function __construct(public readonly int $v) {}
}

/**
 * @param Box12 $box
 */
function twice12($box): int
{
    return $box->v + $box->v;
}

?>
