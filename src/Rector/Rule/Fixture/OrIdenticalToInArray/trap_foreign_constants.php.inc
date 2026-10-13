<?php

namespace App\InArray30;

interface HasState
{
    public const OPEN = 'open';
}

class Base
{
    public const CLOSED = 'closed';
}

class Door extends Base implements HasState
{
    public const AJAR = 'ajar';
    public const SHUT = self::CLOSED;

    public function a(string $s): bool
    {
        return $s === self::OPEN || $s === self::CLOSED;
    }

    public function b(string $s): bool
    {
        return $s === static::AJAR || $s === static::AJAR;
    }

    public function c(string $s): bool
    {
        return $s === self::SHUT || $s === Base::CLOSED;
    }
}

?>
