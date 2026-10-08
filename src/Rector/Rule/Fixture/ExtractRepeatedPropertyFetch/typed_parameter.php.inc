<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Point3
{
    public function __construct(public readonly float $x, public readonly float $y) {}
}

function length3(Point3 $p, Point3 $q): float
{
    return sqrt(($q->x - $p->x) ** 2 + ($q->y - $p->y) ** 2) + $p->x;
}

?>
-----
<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Point3
{
    public function __construct(public readonly float $x, public readonly float $y) {}
}

function length3(Point3 $p, Point3 $q): float
{
    $x = $p->x;
    return sqrt(($q->x - $x) ** 2 + ($q->y - $p->y) ** 2) + $x;
}

?>
