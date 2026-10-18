<?php

namespace App\Interpolate1;

final class Paths
{
    public string $root = '/srv';
    public ?Paths $parent = null;

    /** @var array<string, string> */
    private array $names = ['app' => 'opmin'];

    public function variables(string $dir, string $name, string $ext): string
    {
        return $dir . '/' . $name . '.' . $ext;
    }

    public function properties(string $name): string
    {
        return $this->root . '/' . $this->parent?->root . '/' . $name;
    }

    public function elements(array $row, string $key, int $i): string
    {
        return $row['id'] . ':' . $row[0] . ':' . $row[$key] . ':' . $this->names['app'] . $i;
    }

    public function methods(string $name): string
    {
        return $this->base() . '/' . $name . $this->parent?->base();
    }

    public function literals(int $id, string $name): string
    {
        return 'item ' . 42 . ' #' . $id . ': ' . $name;
    }

    public function interpolated(string $a, string $b): string
    {
        return "<{$a}>" . ' and ' . $b;
    }

    public function base(): string
    {
        return $this->root;
    }
}

?>
-----
<?php

namespace App\Interpolate1;

final class Paths
{
    public string $root = '/srv';
    public ?Paths $parent = null;

    /** @var array<string, string> */
    private array $names = ['app' => 'opmin'];

    public function variables(string $dir, string $name, string $ext): string
    {
        return "{$dir}/{$name}.{$ext}";
    }

    public function properties(string $name): string
    {
        return "{$this->root}/{$this->parent?->root}/{$name}";
    }

    public function elements(array $row, string $key, int $i): string
    {
        return "{$row['id']}:{$row[0]}:{$row[$key]}:{$this->names['app']}{$i}";
    }

    public function methods(string $name): string
    {
        return "{$this->base()}/{$name}{$this->parent?->base()}";
    }

    public function literals(int $id, string $name): string
    {
        return "item 42 #{$id}: {$name}";
    }

    public function interpolated(string $a, string $b): string
    {
        return "<{$a}> and {$b}";
    }

    public function base(): string
    {
        return $this->root;
    }
}

?>
