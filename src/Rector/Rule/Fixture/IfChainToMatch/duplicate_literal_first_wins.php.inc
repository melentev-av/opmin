<?php

namespace App\IfMatch6;

function pick(string $i): string
{
    if ($i === '1') {
        return 'first';
    }
    if ($i === '2') {
        return 'two';
    }
    if ($i === '1') {
        return 'never';
    }

    return 'other';
}

?>
-----
<?php

namespace App\IfMatch6;

function pick(string $i): string
{
    return match ($i) {
        '1' => 'first',
        '2' => 'two',
        '1' => 'never',
        default => 'other',
    };
}

?>
