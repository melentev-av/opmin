<?php

namespace App\Fqn10;

final class Clock
{
    #[\Opmin\Ignore(rules: ['fqn'])]
    public function now(): int
    {
        return time();
    }

    /**
     * @opmin-ignore
     */
    public function stamp(): string
    {
        return date('c');
    }
}

?>
