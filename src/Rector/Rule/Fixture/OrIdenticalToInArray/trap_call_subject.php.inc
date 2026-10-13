<?php

namespace App\InArray21;

final class Box
{
    public function state(): string
    {
        return 'a';
    }

    public function open(): bool
    {
        return $this->state() === 'a' || $this->state() === 'b';
    }
}

?>
