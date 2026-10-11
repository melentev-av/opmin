<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification;

use Opmin\Module\Verification\TimeoutStreak;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(TimeoutStreak::class)]
final class TimeoutStreakTest
{
    public function stopsOnTheLimitInARow(): void
    {
        $streak = new TimeoutStreak(3);

        $answers = [$streak->timedOut(), $streak->timedOut(), $streak->timedOut()];

        Assert::same($answers, [false, false, true]);
        Assert::true($streak->stopped());
    }

    public function anInputThatRanBreaksTheRow(): void
    {
        $streak = new TimeoutStreak(3);

        $streak->timedOut();
        $streak->timedOut();
        $streak->ran();
        $streak->timedOut();

        Assert::false($streak->timedOut());
        Assert::true($streak->timedOut());
    }

    public function neverStopsAfterAFailure(): void
    {
        $streak = new TimeoutStreak(2);

        $streak->failed();
        $streak->timedOut();
        $streak->timedOut();

        Assert::false($streak->timedOut());
        Assert::false($streak->stopped());
    }

    public function staysStopped(): void
    {
        $streak = new TimeoutStreak(1);
        $streak->timedOut();

        $streak->ran();
        $streak->failed();

        Assert::true($streak->stopped());
    }
}
