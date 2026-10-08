<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Box19
{
    public function __construct(private readonly int $v) {}

    public function run(bool $c): int
    {
        $a = 0;
        if ($c) {
            $a = $this->v;
        }
        return $a + $this->v;
    }
}

?>
