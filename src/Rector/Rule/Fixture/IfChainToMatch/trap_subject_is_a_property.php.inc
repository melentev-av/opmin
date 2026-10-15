<?php

namespace App\IfMatchTrap5;

final class Box
{
    public function __get(string $name): string
    {
        return (string) \random_int(1, 2);
    }

    public function label(): string
    {
        if ($this->code === '1') {
            return 'one';
        }
        if ($this->code === '2') {
            return 'two';
        }

        return 'many';
    }
}

?>
