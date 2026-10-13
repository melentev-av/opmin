<?php

namespace App\IfMatch2;

final class Status
{
    public function color(string $status, string $fallback)
    {
        if ('new' === $status) {
            return 'blue';
        }
        if ($status === 'done') {
            return 'green';
        }

        return $fallback . '!';
    }
}

?>
-----
<?php

namespace App\IfMatch2;

final class Status
{
    public function color(string $status, string $fallback)
    {
        return match ($status) {
            'new' => 'blue',
            'done' => 'green',
            default => $fallback . '!',
        };
    }
}

?>
