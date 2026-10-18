<?php

namespace App\Interpolate3;

final class Labels
{
    private const SUFFIX = '.txt';

    public function constantAfter(string $dir, string $name): string
    {
        return $dir . '/' . $name . self::SUFFIX;
    }

    public function callAfter(string $dir, string $name, string $ext): string
    {
        return $dir . '/' . $name . \strtoupper($ext) . '.' . $ext;
    }

    public function nested(string $a, string $b): string
    {
        return $this->wrap($a . ':' . $b) . ' / ' . \trim($b . '-' . $a . ' ');
    }

    public function contexts(string $a, string $b): string
    {
        $s = '';
        $s .= $a . '=' . $b;
        echo $a . ', ' . $b, \PHP_EOL;
        $list = [$a . '/' . $b => $b . '/' . $a];

        return $s . \implode(',', $list);
    }

    private function wrap(string $s): string
    {
        return $s;
    }
}

?>
-----
<?php

namespace App\Interpolate3;

final class Labels
{
    private const SUFFIX = '.txt';

    public function constantAfter(string $dir, string $name): string
    {
        return "{$dir}/{$name}" . self::SUFFIX;
    }

    public function callAfter(string $dir, string $name, string $ext): string
    {
        return "{$dir}/{$name}" . \strtoupper($ext) . '.' . $ext;
    }

    public function nested(string $a, string $b): string
    {
        return $this->wrap("{$a}:{$b}") . ' / ' . \trim("{$b}-{$a} ");
    }

    public function contexts(string $a, string $b): string
    {
        $s = '';
        $s .= "{$a}={$b}";
        echo "{$a}, {$b}", \PHP_EOL;
        $list = ["{$a}/{$b}" => "{$b}/{$a}"];

        return $s . \implode(',', $list);
    }

    private function wrap(string $s): string
    {
        return $s;
    }
}

?>
