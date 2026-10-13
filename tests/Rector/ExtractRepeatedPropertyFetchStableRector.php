<?php

declare(strict_types=1);

namespace Opmin\Tests\Rector;

use Opmin\Rector\Rule\ExtractRepeatedPropertyFetchRector;
use PHPStan\Reflection\ReflectionProvider;
use Rector\Reflection\ReflectionResolver;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * {@see ExtractRepeatedPropertyFetchRector} with the project data of stable properties: the rest of
 * the project writes nothing, so only the fixture file decides (the bridge does not pass rule
 * options, so the options are fixed here).
 */
#[TestRectorFixtures('Fixture/ExtractRepeatedPropertyFetchStable')]
final class ExtractRepeatedPropertyFetchStableRector extends ExtractRepeatedPropertyFetchRector
{
    public function __construct(ReflectionResolver $reflectionResolver, ReflectionProvider $reflectionProvider)
    {
        parent::__construct($reflectionResolver, $reflectionProvider);
        $this->configure([self::EFFECTS => []]);
    }
}
