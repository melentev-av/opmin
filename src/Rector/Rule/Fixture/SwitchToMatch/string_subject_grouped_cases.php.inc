<?php

namespace App\SwitchMatch2;

final class Status
{
    public function color(string $status): string
    {
        switch ($status) {
            case 'new':
            case 'open':
                return 'blue';
            case 'done':
                return 'green';
            default:
                return 'grey';
        }
    }
}

?>
-----
<?php

namespace App\SwitchMatch2;

final class Status
{
    public function color(string $status): string
    {
        return match ($status) {
            'new', 'open' => 'blue',
            'done' => 'green',
            default => 'grey',
        };
    }
}

?>
