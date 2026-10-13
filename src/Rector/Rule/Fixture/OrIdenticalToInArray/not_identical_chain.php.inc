<?php

namespace App\InArray2;

function label(string $s): string
{
    if ($s !== 'draft' && $s !== 'hidden') {
        return $s;
    }

    return '';
}

?>
-----
<?php

namespace App\InArray2;

function label(string $s): string
{
    if (!\in_array($s, ['draft', 'hidden'], true)) {
        return $s;
    }

    return '';
}

?>
