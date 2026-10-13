<?php

namespace App\Sprintf19;

final class Ignored
{
    #[\Opmin\Ignore]
    public function all(string $a, string $b): string
    {
        return \sprintf('%s-%s', $a, $b);
    }

    /** @opmin-ignore sprintf */
    public function alias(string $a, string $b): string
    {
        return \sprintf('%s-%s', $a, $b);
    }
}

?>
