<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

function str_word_count(string $s): int
{
    Counter40::$last?->bump();
    return 0;
}

final class Counter40
{
    public static ?self $last = null;

    private int $v = 1;

    public function bump(): void
    {
        $this->v++;
    }

    public function words(string $s): int
    {
        return $this->v + str_word_count($s) + $this->v;
    }
}

?>
