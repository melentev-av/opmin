<?php

declare(strict_types=1);

namespace Opmin\Tests\Rector;

use Opmin\Rector\Rule\SprintfToConcatRector;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * {@see SprintfToConcatRector} for `php.target` 8.4: the compiler makes a rope of `sprintf()` itself,
 * cheaper than the concatenation, so the rule leaves every call alone.
 */
#[TestRectorFixtures('Fixture/SprintfToConcatPhp84')]
final class SprintfToConcatPhp84Rector extends SprintfToConcatRector
{
    protected function phpTarget(): int
    {
        return 80400;
    }
}
