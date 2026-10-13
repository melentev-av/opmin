<?php

namespace App\StartsWith2;

function literal(string $s): bool
{
    return \strpos($s, 'abc') === 0;
}

function variable(string $s, string $prefix): bool
{
    return 0 === \strpos($s, $prefix);
}

function negated(string $s): int
{
    if (\strpos($s, '#') !== 0) {
        return 1;
    }

    return 2;
}

?>
-----
<?php

namespace App\StartsWith2;

function literal(string $s): bool
{
    return \str_starts_with($s, 'abc');
}

function variable(string $s, string $prefix): bool
{
    return \str_starts_with($s, $prefix);
}

function negated(string $s): int
{
    if (!\str_starts_with($s, '#')) {
        return 1;
    }

    return 2;
}

?>
