<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Unset21
{
    private $v = 1;

    public function drop(): void
    {
        unset($this->v);
    }

    public function twice(): int
    {
        return $this->v + $this->v;
    }
}

?>
