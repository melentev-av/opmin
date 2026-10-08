<?php

namespace App\Fqn1;

function describe(string $s, array $a): string
{
    if (is_array($a) && in_array($s, $a, true)) {
        return strlen($s) . PHP_EOL . count($a) . M_PI;
    }

    return sprintf('%s', $s) . DIRECTORY_SEPARATOR;
}

?>
-----
<?php

namespace App\Fqn1;

function describe(string $s, array $a): string
{
    if (\is_array($a) && \in_array($s, $a, true)) {
        return \strlen($s) . \PHP_EOL . \count($a) . \M_PI;
    }

    return \sprintf('%s', $s) . \DIRECTORY_SEPARATOR;
}

?>
