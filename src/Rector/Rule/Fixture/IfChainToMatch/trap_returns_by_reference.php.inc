<?php

namespace App\IfMatchTrap15;

final class Registry
{
    private array $a = [];
    private array $b = [];

    public function &pick(string $i): array
    {
        if ($i === '1') {
            return $this->a;
        }
        if ($i === '2') {
            return $this->b;
        }

        return $this->a;
    }
}

?>
