<?php

namespace App\Sprintf17;

final class Format
{
    private const PAIR = '%s-%s';

    public function constant(string $a, string $b): string
    {
        return \sprintf(self::PAIR, $a, $b);
    }

    public function interpolated(string $a, string $b, string $sep): string
    {
        return \sprintf("%s{$sep}%s", $a, $b);
    }

    public function variable(string $format, string $a): string
    {
        return \sprintf($format, $a);
    }
}

?>
