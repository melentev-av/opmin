<?php

namespace App\StartsWith1;

final class Paths
{
    public function absolute(string $path): bool
    {
        return \substr($path, 0, 1) === '/';
    }

    public function vendor(string $path): bool
    {
        return 'vendor/' === \substr($path, 0, 7);
    }

    public function other(string $path): int
    {
        if (\substr($path, 0, 3) !== 'src') {
            return 1;
        }

        return 2;
    }

    public function both(bool $flag, string $path): bool
    {
        return $flag && \substr(\trim($path), 0, 2) !== './';
    }
}

?>
-----
<?php

namespace App\StartsWith1;

final class Paths
{
    public function absolute(string $path): bool
    {
        return \str_starts_with($path, '/');
    }

    public function vendor(string $path): bool
    {
        return \str_starts_with($path, 'vendor/');
    }

    public function other(string $path): int
    {
        if (!\str_starts_with($path, 'src')) {
            return 1;
        }

        return 2;
    }

    public function both(bool $flag, string $path): bool
    {
        return $flag && !\str_starts_with(\trim($path), './');
    }
}

?>
