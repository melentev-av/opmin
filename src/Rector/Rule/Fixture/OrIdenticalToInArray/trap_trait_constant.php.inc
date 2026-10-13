<?php

namespace App\InArray31;

trait States
{
    public const A = 'a';
    public const B = 'b';

    public function known(string $s): bool
    {
        return $s === self::A || $s === self::B;
    }
}

?>
