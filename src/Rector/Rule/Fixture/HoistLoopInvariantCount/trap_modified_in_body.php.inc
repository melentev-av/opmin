<?php

namespace App\Count3;

function drain(array $queue): array
{
    $out = [];
    for ($i = 0; $i < \count($queue); $i++) {
        if ($queue[$i] > 0) {
            $queue[] = $queue[$i] - 1;
        }
        $out[] = $queue[$i];
    }

    return $out;
}

?>
