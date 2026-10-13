<?php

namespace App\ArrayPush18;

final class Ignored
{
    #[\Opmin\Ignore]
    public function all(array $a, int $v): array
    {
        \array_push($a, $v);

        return $a;
    }

    /** @opmin-ignore array_push */
    public function alias(array $a, int $v): array
    {
        \array_push($a, $v);

        return $a;
    }
}

?>
