<?php

namespace App\Sprintf2;

function share(int $part, string $unit): string
{
    return \sprintf("%d%% of %s\n", $part, $unit);
}

function quoted(string $a, string $b): string
{
    return \sprintf('it\'s %s or %s', $a, $b);
}

?>
-----
<?php

namespace App\Sprintf2;

function share(int $part, string $unit): string
{
    return $part . "% of " . $unit . "\n";
}

function quoted(string $a, string $b): string
{
    return 'it\'s ' . $a . ' or ' . $b;
}

?>
