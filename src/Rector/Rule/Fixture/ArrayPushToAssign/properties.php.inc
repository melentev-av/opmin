<?php

namespace App\ArrayPush3;

final class Log
{
    private static array $cache = [];

    protected array $lines = [];

    public function add(string $line): void
    {
        \array_push($this->lines, $line);
        \array_push(self::$cache, $line);
        \array_push(Log::$cache, \strlen($line));
    }

    public function other(Log $log, string $line): void
    {
        \array_push($log->lines, $line);
    }
}

?>
-----
<?php

namespace App\ArrayPush3;

final class Log
{
    private static array $cache = [];

    protected array $lines = [];

    public function add(string $line): void
    {
        $this->lines[] = $line;
        self::$cache[] = $line;
        Log::$cache[] = \strlen($line);
    }

    public function other(Log $log, string $line): void
    {
        $log->lines[] = $line;
    }
}

?>
