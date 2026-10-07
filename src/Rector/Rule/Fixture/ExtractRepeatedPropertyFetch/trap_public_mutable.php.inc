<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Box15
{
    public int $v = 1;

    public function twice(): int
    {
        return $this->v + $this->v;
    }
}

?>
