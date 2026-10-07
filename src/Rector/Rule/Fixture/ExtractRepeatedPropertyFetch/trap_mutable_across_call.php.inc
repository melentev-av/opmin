<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Box16
{
    private int $v = 1;

    public function run(): int
    {
        $a = $this->v;
        $this->touch();
        return $a + $this->v;
    }

    private function touch(): void
    {
        $this->v++;
    }
}

?>
