<?php

namespace App\Sprintf3;

function name(string $s, string $t): string
{
    return \sprintf('%s', $s) . \sprintf('[%s]', $t);
}

function number(int $i, string $s): string
{
    return \sprintf('%d', $i) . \sprintf('%s-%s', $s, $s);
}

function text(string $s): string
{
    return \sprintf('plain') . \sprintf('100%%') . \sprintf('') . \sprintf('%s-%s', $s, $s);
}

?>
-----
<?php

namespace App\Sprintf3;

function name(string $s, string $t): string
{
    return $s . ('[' . $t . ']');
}

function number(int $i, string $s): string
{
    return (string) $i . ($s . '-' . $s);
}

function text(string $s): string
{
    return 'plain' . '100%' . '' . ($s . '-' . $s);
}

?>
