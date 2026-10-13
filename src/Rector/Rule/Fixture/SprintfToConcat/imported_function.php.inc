<?php

namespace App\Sprintf4;

use function sprintf;

function path(string $a, string $b): string
{
    return sprintf('%s/%s', $a, $b);
}

?>
-----
<?php

namespace App\Sprintf4;

use function sprintf;

function path(string $a, string $b): string
{
    return $a . '/' . $b;
}

?>
