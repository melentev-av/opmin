<?php

namespace App\SwitchMatchTrap20;

final class Request
{
    public function __construct(private mixed $method) {}

    public function method()
    {
        return $this->method;
    }

    public function safe(): bool
    {
        switch ($this->method()) {
            case 'GET':
                return true;
            case 'POST':
                return false;
            default:
                return false;
        }
    }
}

?>
