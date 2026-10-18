<?php

namespace App\MatchToIf1;

final class Sign
{
    public function name(int $n): string
    {
        return match (true) {
            $n < 0 => 'negative',
            $n === 0 => 'zero',
            default => 'positive',
        };
    }

    public function kind(mixed $v): string
    {
        // Picks the kind.
        return match (true) {
            \is_int($v) => 'int',
            $v instanceof \Stringable => (string) $v,
            default => throw new \InvalidArgumentException('unknown'),
        };
    }

    public function defaultFirst(int $n): int
    {
        return match (true) {
            default => 0,
            $n > 10 => 2,
            $n > 5 => 1,
        };
    }
}

?>
-----
<?php

namespace App\MatchToIf1;

final class Sign
{
    public function name(int $n): string
    {
        if ($n < 0) {
            return 'negative';
        }
        if ($n === 0) {
            return 'zero';
        }
        return 'positive';
    }

    public function kind(mixed $v): string
    {
        // Picks the kind.
        if (\is_int($v)) {
            return 'int';
        }
        if ($v instanceof \Stringable) {
            return (string) $v;
        }
        throw new \InvalidArgumentException('unknown');
    }

    public function defaultFirst(int $n): int
    {
        if ($n > 10) {
            return 2;
        }
        if ($n > 5) {
            return 1;
        }
        return 0;
    }
}

?>
