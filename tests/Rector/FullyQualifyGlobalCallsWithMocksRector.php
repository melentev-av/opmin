<?php

declare(strict_types=1);

namespace Opmin\Tests\Rector;

use Opmin\Rector\Rule\FullyQualifyGlobalCallsRector;
use PHPStan\Reflection\ReflectionProvider;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * {@see FullyQualifyGlobalCallsRector} with a project where a test mocks `time()` in `App\Clock`
 * (ClockMock) and php-mock mocks every function of `App\Mocked`: the bridge does not pass rule
 * options, so the options are fixed here.
 */
#[TestRectorFixtures('Fixture/FullyQualifyGlobalCallsWithMocks')]
final class FullyQualifyGlobalCallsWithMocksRector extends FullyQualifyGlobalCallsRector
{
    public function __construct(ReflectionProvider $reflectionProvider)
    {
        parent::__construct($reflectionProvider);
        $this->configure([self::SHADOWS => [
            'functions' => [],
            'constants' => [],
            'mocks' => [['app\clock', 'time'], ['app\mocked', null]],
        ]]);
    }
}
