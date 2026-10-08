<?php

namespace App\Count5;

final class Sized implements \Countable
{
    private int $calls = 0;

    public function count(): int
    {
        return 3 - $this->calls++;
    }
}

function run(Sized $s): int
{
    $k = 0;
    for ($i = 0; $i < \count($s); $i++) {
        $k++;
    }

    return $k;
}

?>
