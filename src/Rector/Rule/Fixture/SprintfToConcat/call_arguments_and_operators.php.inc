<?php

namespace App\Sprintf5;

function line(string $s, string $t): string
{
    return \sprintf('%s: %d', \trim($s), \strlen($t));
}

function after(string $s, string $t): bool
{
    return \sprintf('%s-%s', $s, \strtoupper($t)) === $t;
}

function closure(string $s): \Closure
{
    return static fn(string $t): string => \sprintf('%s-%s', $s, $t);
}

?>
-----
<?php

namespace App\Sprintf5;

function line(string $s, string $t): string
{
    return \trim($s) . ': ' . \strlen($t);
}

function after(string $s, string $t): bool
{
    return $s . '-' . \strtoupper($t) === $t;
}

function closure(string $s): \Closure
{
    return static fn(string $t): string => $s . '-' . $t;
}

?>
