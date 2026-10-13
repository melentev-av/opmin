<?php

namespace App\InArray32;

final class Checks
{
    #[\Opmin\Ignore(rules: ['in_array'])]
    public function a(string $s): bool
    {
        return $s === 'a' || $s === 'b';
    }

    /**
     * @opmin-ignore in_array
     */
    public function b(string $s): bool
    {
        return $s === 'a' || $s === 'b';
    }
}

?>
