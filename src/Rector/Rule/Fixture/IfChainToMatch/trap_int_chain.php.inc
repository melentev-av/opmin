<?php

namespace App\IfMatch1;

// Integer labels are left alone: on PHP 8.1–8.5 `match` costs more than the chain for up to 5 arms
// without JIT and for almost any size with the tracing JIT (plan: cost matrix).

function label(int $code): string
{
    if ($code === 1) {
        return 'one';
    }
    if ($code === 2) {
        return 'two';
    }
    if ($code === 3) {
        return 'three';
    }

    return 'many';
}

?>
