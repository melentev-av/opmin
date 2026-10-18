<?php

namespace App\Interpolate7;

final class Ignored
{
    #[\Opmin\Ignore]
    public function all(string $a, string $b): string
    {
        return $a . '-' . $b;
    }

    /** @opmin-ignore interpolate */
    public function alias(string $a, string $b): string
    {
        return $a . '-' . $b;
    }
}

?>
