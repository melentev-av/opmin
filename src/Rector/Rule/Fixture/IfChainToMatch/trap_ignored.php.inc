<?php

namespace App\IfMatchTrap16;

final class Ignored
{
    #[\Opmin\Ignore]
    public function one(int $i): string
    {
        if ($i === 1) {
            return 'one';
        }
        if ($i === 2) {
            return 'two';
        }

        return 'many';
    }

    /** @opmin-ignore if_to_match */
    public function two(int $i): string
    {
        if ($i === 1) {
            return 'one';
        }
        if ($i === 2) {
            return 'two';
        }

        return 'many';
    }
}

?>
