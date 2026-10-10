<?php

// Fixture for opcode counting: PHP 8.4+ compiles an unqualified call of a frameless function (trim,
// str_replace…) in a namespace twice — the frameless call and the fallback — so a closure among its
// arguments is dumped twice. Each closure is still one function of the source.

declare(strict_types=1);

namespace Fixture\Count;

final class Frameless
{
    public function camel(string $s): string
    {
        return trim(implode('', array_map(static function (string $c): string {
            static $i = 0;

            return ++$i . $c;
        }, str_split($s))));
    }

    public function twoOnOneLine(array $a): string
    {
        return trim(implode(',', array_map(fn($x) => $x, $a)) . implode(',', array_map(fn($x) => $x, $a)));
    }

    public function nested(array $a): string
    {
        return trim((string) array_reduce($a, function ($carry, $x) {
            return $carry . implode('', array_map(fn($y) => $y, [$x]));
        }, ''));
    }

    public function qualified(array $a): string
    {
        return \trim(\implode('', \array_map(fn($x) => $x, $a)));
    }

    public function after(int $x): int
    {
        return $x + 1;
    }
}
