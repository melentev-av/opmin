<?php

namespace App\MatchToIf4;

final class Forms
{
    private string $kind = '';

    public function noDefault(int $n): string
    {
        return match (true) {
            $n > 0 => 'positive',
            $n < 0 => 'negative',
        };
    }

    public function severalConditions(int $n): string
    {
        return match (true) {
            $n < 0, $n > 100 => 'out',
            default => 'in',
        };
    }

    public function notTrue(int $n): string
    {
        return match (false) {
            $n > 0 => 'not positive',
            default => 'positive',
        };
    }

    public function subject(int $n): string
    {
        return match ($n) {
            1 => 'one',
            default => 'many',
        };
    }

    public function property(int $n): void
    {
        $this->kind = match (true) {
            $n > 0 => 'positive',
            default => 'other',
        };
    }

    public function argument(int $n): int
    {
        return \strlen(match (true) {
            $n > 0 => 'positive',
            default => 'other',
        });
    }

    public function &byReference(array &$a, bool $first): mixed
    {
        return match (true) {
            $first => $a[0],
            default => $a[1],
        };
    }

    /** @opmin-ignore match_to_if */
    public function ignored(int $n): string
    {
        return match (true) {
            $n > 0 => 'positive',
            default => 'other',
        };
    }
}

?>
