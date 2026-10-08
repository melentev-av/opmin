<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Lazy31
{
    private readonly int $v;

    public function run(array $list): array
    {
        return [\array_map(fn(int $i): int => $this->init($i), $list), $this->v, $this->v];
    }

    private function init(int $i): int
    {
        $this->v = $i;
        return $i;
    }
}

?>
