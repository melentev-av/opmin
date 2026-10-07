<?php

namespace App\Mocked;

function run(string $s): int
{
    return strlen($s) + count([]);
}

?>
