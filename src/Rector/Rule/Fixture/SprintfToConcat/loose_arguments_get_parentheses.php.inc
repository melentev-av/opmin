<?php

namespace App\Sprintf20;

function coverage(float|int|null $coverage, ?int $inputs): string
{
    return \sprintf('diff-tested %s%%%s ✓', \is_float($coverage) || \is_int($coverage) ? (string) \round((float) $coverage, 1) : '?', \is_int($inputs) ? " ({$inputs} inputs)" : '');
}

function fallback(?string $name, string $kind): string
{
    return \sprintf('%s: %s', $name ?? 'none', $kind);
}

function order(int $a, int $b, string $s): string
{
    return \sprintf('%d %s', $a <=> $b, $s);
}

function assigned(string $s): string
{
    # PHP reads an assignment on the right of `.` whole: `'/' . ($t = 'x')`.
    return \sprintf('%s/%s', \trim($s), $t = 'x');
}

function alone(bool $c, string $a, string $b): string
{
    return '<' . \sprintf('%s', $c ? $a : $b);
}

function sums(int $a, int $b, string $s): string
{
    return \sprintf('%d-%s', $a + $b, $s);
}

?>
-----
<?php

namespace App\Sprintf20;

function coverage(float|int|null $coverage, ?int $inputs): string
{
    return 'diff-tested ' . (\is_float($coverage) || \is_int($coverage) ? (string) \round((float) $coverage, 1) : '?') . '%' . (\is_int($inputs) ? " ({$inputs} inputs)" : '') . ' ✓';
}

function fallback(?string $name, string $kind): string
{
    return ($name ?? 'none') . ': ' . $kind;
}

function order(int $a, int $b, string $s): string
{
    return ($a <=> $b) . ' ' . $s;
}

function assigned(string $s): string
{
    # PHP reads an assignment on the right of `.` whole: `'/' . ($t = 'x')`.
    return \trim($s) . '/' . $t = 'x';
}

function alone(bool $c, string $a, string $b): string
{
    return '<' . ($c ? $a : $b);
}

function sums(int $a, int $b, string $s): string
{
    return $a + $b . '-' . $s;
}

?>
