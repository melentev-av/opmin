<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Counter39 implements \Stringable
{
    private int $v = 1;

    public function clock(): int
    {
        return $this->v + \time() + $this->v;
    }

    public function callback(array $list): int
    {
        return $this->v + \count(\array_map(fn(int $i): int => $this->v = $i, $list)) + $this->v;
    }

    public function autoload(string $class): int
    {
        return $this->v + (int) \class_exists($class) + $this->v;
    }

    public function deprecation(int $byte): int
    {
        return $this->v + \strlen(\chr($byte)) + $this->v;
    }

    public function optionalArgument(string $s, string $charset): int
    {
        return $this->v + \strlen(\htmlspecialchars($s, \ENT_QUOTES, $charset)) + $this->v;
    }

    public function toString(): int
    {
        return $this->v + \str_word_count($this) + $this->v;
    }

    public function __toString(): string
    {
        $this->v++;
        return 'x';
    }
}

?>
