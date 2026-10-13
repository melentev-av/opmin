<?php

namespace App\SwitchMatchTrap17;

final class Codes
{
    public const ONE = 1;

    public function label(int $code): string
    {
        switch ($code) {
            case self::ONE:
                return 'one';
            case 2:
                return 'two';
            default:
                return 'many';
        }
    }
}

?>
