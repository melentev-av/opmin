<?php

namespace App\InArray25;

function local(array $config): bool
{
    return $config['host'] === 'localhost' || $config['host'] === '127.0.0.1';
}

?>
