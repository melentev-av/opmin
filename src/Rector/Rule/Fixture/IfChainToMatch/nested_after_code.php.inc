<?php

namespace App\IfMatch5;

function describe(array $items, $mode)
{
    $out = [];
    foreach ($items as $item) {
        $out[] = static function () use ($item) {
            $x = $item;
            if ($x === 'a') {
                return 1;
            }
            if ($x === 'b') {
                return 2;
            }

            return 0;
        };
    }

    if ($out !== []) {
        // The mode decides.
        if ($mode === 1) {
            return [$out];
        }
        if ($mode === 2) {
            return $out;
        }

        return [];
    }

    return $items;
}

?>
-----
<?php

namespace App\IfMatch5;

function describe(array $items, $mode)
{
    $out = [];
    foreach ($items as $item) {
        $out[] = static function () use ($item) {
            $x = $item;
            return match ($x) {
                'a' => 1,
                'b' => 2,
                default => 0,
            };
        };
    }

    if ($out !== []) {
        // The mode decides.
        return match ($mode) {
            1 => [$out],
            2 => $out,
            default => [],
        };
    }

    return $items;
}

?>
