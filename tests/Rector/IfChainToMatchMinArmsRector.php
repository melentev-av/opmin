<?php

declare(strict_types=1);

namespace Opmin\Tests\Rector;

use Opmin\Rector\Rule\IfChainToMatchRector;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * {@see IfChainToMatchRector} with `min_arms: 3`: the bridge does not pass rule options, so the
 * option is fixed here.
 */
#[TestRectorFixtures('Fixture/IfChainToMatchMinArms')]
final class IfChainToMatchMinArmsRector extends IfChainToMatchRector
{
    public function __construct()
    {
        $this->configure([self::MIN_ARMS => 3]);
    }
}
