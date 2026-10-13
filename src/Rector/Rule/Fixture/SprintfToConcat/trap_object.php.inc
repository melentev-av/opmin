<?php

namespace App\Sprintf7;

function label(\Stringable $o, string $s): string
{
    return \sprintf('%s-%s', $o, $s);
}

?>
