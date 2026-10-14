<?php

namespace App\Sprintf21;

final class Contexts
{
    public function contexts(string $a, string $b, int $i, int $j, bool $c, ?string $n): array
    {
        return [
            \sprintf('%s-%s', $a, $b)[0],
            -\sprintf('%d%d', $i, $j),
            !\sprintf('%s-%s', $a, $b),
            (int) \sprintf('%d%d', $i, $j),
            @\sprintf('%s-%s', $a, $b),
            \sprintf('%d%d', $i, $j) ** 2,
            $i * \sprintf('%d%d', $i, $j),
            \sprintf('%s-%s', $a, $b) instanceof Contexts,
            $n ?? \sprintf('%s-%s', $a, $b),
            $c ? \sprintf('%s-%s', $a, $b) : '',
            \sprintf('%s-%s', $a, $b) ?: 'x',
            \sprintf('%s-%s', $a, $b) === $a,
            -\sprintf('%s', $c ? $a : $b),
            \sprintf('%s', $n ?? $a)[0],
        ];
    }

    public function more(string $a, string $b): \Generator
    {
        yield \sprintf('%s-%s', $a, $b);
        print \sprintf('%s-%s', $a, $b);
        $x = clone \sprintf('%s-%s', $a, $b);
    }
}

?>
-----
<?php

namespace App\Sprintf21;

final class Contexts
{
    public function contexts(string $a, string $b, int $i, int $j, bool $c, ?string $n): array
    {
        return [
            ($a . '-' . $b)[0],
            -($i . $j),
            !($a . '-' . $b),
            (int) ($i . $j),
            @($a . '-' . $b),
            ($i . $j) ** 2,
            $i * ($i . $j),
            ($a . '-' . $b) instanceof Contexts,
            $n ?? $a . '-' . $b,
            $c ? $a . '-' . $b : '',
            $a . '-' . $b ?: 'x',
            $a . '-' . $b === $a,
            -($c ? $a : $b),
            ($n ?? $a)[0],
        ];
    }

    public function more(string $a, string $b): \Generator
    {
        yield $a . '-' . $b;
        print $a . '-' . $b;
        $x = clone ($a . '-' . $b);
    }
}

?>
