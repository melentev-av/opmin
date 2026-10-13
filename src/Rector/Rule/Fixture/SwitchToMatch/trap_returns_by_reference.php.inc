<?php

namespace App\SwitchMatchTrap22;

final class Registry
{
    private array $a = [];
    private array $b = [];

    public function &pick(int $i): array
    {
        switch ($i) {
            case 1:
                return $this->a;
            case 2:
                return $this->b;
            default:
                return $this->a;
        }
    }
}

?>
