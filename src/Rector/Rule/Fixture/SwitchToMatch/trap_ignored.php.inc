<?php

namespace App\SwitchMatchTrap21;

final class Ignored
{
    #[\Opmin\Ignore(rules: ['switch_to_match'])]
    public function label(int $code): string
    {
        switch ($code) {
            case 1:
                return 'one';
            case 2:
                return 'two';
            default:
                return 'many';
        }
    }
}

?>
