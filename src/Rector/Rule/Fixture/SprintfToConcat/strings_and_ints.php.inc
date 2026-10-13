<?php

namespace App\Sprintf1;

final class Labels
{
    public function __construct(private readonly string $prefix) {}

    public function pair(string $a, string $b): string
    {
        return \sprintf('%s-%s', $a, $b);
    }

    public function item(int $id, string $name): string
    {
        return \sprintf('#%d: %s', $id, $name);
    }

    public function key(int|string $key, string $value): string
    {
        return \sprintf('%s=%s;', $key, $value);
    }

    public function prefixed(string $name): string
    {
        return \sprintf('%s.%s', $this->prefix, $name);
    }
}

?>
-----
<?php

namespace App\Sprintf1;

final class Labels
{
    public function __construct(private readonly string $prefix) {}

    public function pair(string $a, string $b): string
    {
        return $a . '-' . $b;
    }

    public function item(int $id, string $name): string
    {
        return '#' . $id . ': ' . $name;
    }

    public function key(int|string $key, string $value): string
    {
        return $key . '=' . $value . ';';
    }

    public function prefixed(string $name): string
    {
        return $this->prefix . '.' . $name;
    }
}

?>
