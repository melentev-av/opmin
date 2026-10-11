<?php

declare(strict_types=1);

namespace App;

final class Typo
{
    /** @opmin-ignore fqm */
    public function typo(array $a): int
    {
        return count($a);
    }

    /** @opmin-ignore \Opmin\Rector\Rule\FullyQualifyGlobalCallsRector */
    public function fullName(array $a): int
    {
        return count($a);
    }

    #[\Opmin\Ignore(rules: ['Opmin\Rector\Rule\FullyQualifyGlobalCallsRector'])]
    public function fullNameInTheAttribute(array $a): int
    {
        return count($a);
    }
}
