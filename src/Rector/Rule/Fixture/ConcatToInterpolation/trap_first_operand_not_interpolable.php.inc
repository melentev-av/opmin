<?php

namespace App\Interpolate5;

final class Mixed_
{
    private const PREFIX = 'p';
    public static string $static = 's';

    public function call(string $a, string $b): string
    {
        return \strtoupper($a) . '/' . $a . '/' . $b;
    }

    public function constant(string $a, string $b): string
    {
        return self::PREFIX . $a . '/' . $b;
    }

    public function staticProperty(string $a, string $b): string
    {
        return self::$static . $a . '/' . $b;
    }

    public function dynamicKey(array $a, string $b): string
    {
        return $a[\count($a) - 1] . $b . '/' . $b;
    }

    public function negativeKey(array $a, string $b): string
    {
        return $a[-1] . $b . '/' . $b;
    }

    public function dynamicName(string $name, string $b): string
    {
        return $$name . $b . '/' . $b;
    }

    public function dynamicProperty(object $o, string $p, string $b): string
    {
        return $o->{$p} . $b . '/' . $b;
    }

    public function float(string $b): string
    {
        return 1.5 . $b . '/' . $b;
    }

    public function ternary(bool $c, string $b): string
    {
        return ($c ? 'y' : 'n') . $b . '/' . $b;
    }

    public function parenthesizedRight(string $a, string $b): string
    {
        return $a . ($b . '/');
    }
}

?>
