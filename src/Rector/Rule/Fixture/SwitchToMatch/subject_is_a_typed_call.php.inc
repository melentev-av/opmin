<?php

namespace App\SwitchMatch6;

final class Request
{
    public function __construct(private string $method) {}

    public function method(): string
    {
        return \strtoupper($this->method);
    }

    public function safe(): bool
    {
        switch ($this->method()) {
            case 'GET':
            case 'HEAD':
                return true;
            case 'POST':
                return false;
            default:
                return false;
        }
    }
}

?>
-----
<?php

namespace App\SwitchMatch6;

final class Request
{
    public function __construct(private string $method) {}

    public function method(): string
    {
        return \strtoupper($this->method);
    }

    public function safe(): bool
    {
        return match ($this->method()) {
            'GET', 'HEAD' => true,
            'POST' => false,
            default => false,
        };
    }
}

?>
