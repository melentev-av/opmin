<?php

namespace App\MatchToIf2;

function label(int $n, bool $loud): string
{
    $label = match (true) {
        $n < 0 => 'negative',
        $n === 0 => 'zero',
        default => 'positive',
    };

    return $loud ? \strtoupper($label) : $label;
}

function loose(?int $a, bool $flag): int|bool
{
    $v = match (true) {
        $flag => $a ?? 0,
        default => $flag and $a,
    };

    return $v;
}

function nested(array $items): array
{
    foreach ($items as $i => $item) {
        $items[$i] = match (true) {
            \is_string($item) => 's',
            default => 'x',
        };
        $kind = match (true) {
            \is_int($item) => 'i',
            default => throw new \LogicException(),
        };
    }

    return $items;
}

?>
-----
<?php

namespace App\MatchToIf2;

function label(int $n, bool $loud): string
{
    if ($n < 0) {
        $label = 'negative';
    } elseif ($n === 0) {
        $label = 'zero';
    } else {
        $label = 'positive';
    }

    return $loud ? \strtoupper($label) : $label;
}

function loose(?int $a, bool $flag): int|bool
{
    if ($flag) {
        $v = $a ?? 0;
    } else {
        $v = ($flag and $a);
    }

    return $v;
}

function nested(array $items): array
{
    foreach ($items as $i => $item) {
        $items[$i] = match (true) {
            \is_string($item) => 's',
            default => 'x',
        };
        if (\is_int($item)) {
            $kind = 'i';
        } else {
            throw new \LogicException();
        }
    }

    return $items;
}

?>
