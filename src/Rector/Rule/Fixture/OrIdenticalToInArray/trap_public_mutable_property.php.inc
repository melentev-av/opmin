<?php

namespace App\InArray24;

class Entity
{
    public string $state = 'a';
}

function open(Entity $e): bool
{
    return $e->state === 'a' || $e->state === 'b';
}

?>
