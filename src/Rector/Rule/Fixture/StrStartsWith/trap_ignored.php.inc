<?php

namespace App\StartsWith10;

final class Ignored
{
    #[\Opmin\Ignore]
    public function all(string $s): bool
    {
        return \substr($s, 0, 3) === 'abc';
    }

    /** @opmin-ignore str_starts_with */
    public function alias(string $s): bool
    {
        return \strpos($s, 'abc') === 0;
    }
}

?>
