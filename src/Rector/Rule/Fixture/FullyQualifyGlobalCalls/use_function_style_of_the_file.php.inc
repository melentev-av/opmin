<?php

namespace App\Fqn4;

use function sprintf;

function show(string $s): string
{
    return sprintf('%d', strlen($s)) . PHP_EOL;
}

?>
-----
<?php

namespace App\Fqn4;

use function sprintf;
use function strlen;
use const PHP_EOL;

function show(string $s): string
{
    return sprintf('%d', strlen($s)) . PHP_EOL;
}

?>
