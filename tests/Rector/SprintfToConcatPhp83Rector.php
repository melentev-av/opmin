<?php

declare(strict_types=1);

namespace Opmin\Tests\Rector;

use Opmin\Rector\Rule\SprintfToConcatRector;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * {@see SprintfToConcatRector} for `php.target` 8.3, where it fires: the bridge runs Rector with the
 * PHP of the tests as the target, so the target is fixed here.
 */
#[TestRectorFixtures(__DIR__ . '/../../src/Rector/Rule/Fixture/SprintfToConcat')]
final class SprintfToConcatPhp83Rector extends SprintfToConcatRector
{
    protected function phpTarget(): int
    {
        return 80300;
    }
}
