<?php

namespace App\Fqn2;

final class Words
{
    public function upper(array $words): array
    {
        return array_map(static fn(string $w): string => strtoupper($w), array_values($words));
    }
}

?>
-----
<?php

namespace App\Fqn2;

final class Words
{
    public function upper(array $words): array
    {
        return \array_map(static fn(string $w): string => \strtoupper($w), \array_values($words));
    }
}

?>
