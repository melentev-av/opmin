<?php

namespace App\SwitchMatch7;

function first(array $words)
{
    foreach ($words as $word) {
        if (!\is_string($word)) {
            continue;
        }
        switch ($word) {
            case 'a':
                return 1;
            case 'b':
                return 2;
        }
        return 0;
    }

    return -1;
}

?>
-----
<?php

namespace App\SwitchMatch7;

function first(array $words)
{
    foreach ($words as $word) {
        if (!\is_string($word)) {
            continue;
        }
        return match ($word) {
            'a' => 1,
            'b' => 2,
            default => 0,
        };
    }

    return -1;
}

?>
