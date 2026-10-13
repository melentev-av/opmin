<?php

namespace App\IfMatchTrap4;

final class Counter
{
    private int $n = 0;

    public function next(): int
    {
        return ++$this->n;
    }

    public function label(): string
    {
        if ($this->next() === 1) {
            return 'one';
        }
        if ($this->next() === 2) {
            return 'two';
        }

        return 'many';
    }
}

?>
