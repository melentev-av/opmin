<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

/**
 * Methods without native types: checks the `signatures` rules
 * (private ones may get types, public overridable ones may not).
 */
class Signatures
{
    public function add($a, $b)
    {
        return $a + $b;
    }

    public function quadruple($x)
    {
        return $this->double($this->double($x));
    }

    private function double($x)
    {
        return $x * 2;
    }
}
