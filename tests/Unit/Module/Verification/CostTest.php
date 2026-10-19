<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification;

use Opmin\Module\Verification\Cost;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(Cost::class)]
final class CostTest
{
    /**
     * @param list<array{float, float}> $off
     * @param list<array{float, float}> $tracing
     */
    #[DataSet([[[100.0, 90.0], [200.0, 180.0]], [[50.0, 45.0]], true, null], 'cheaper everywhere')]
    #[DataSet([[[100.0, 112.0]], [[50.0, 45.0]], true, null], 'more by 12 %, but only 12 instructions')]
    #[DataSet([[[1000.0, 1040.0]], [[50.0, 45.0]], true, null], 'more by 40 instructions, but only 4 %')]
    #[DataSet([[[100.0, 90.0], [100.0, 90.0], [100.0, 120.0]], [[50.0, 45.0]], true, 'costs more without the JIT: +0 instructions per call on average (+0.0%), 1 of 3 inputs beyond guard_cost (5% and 15 instructions)'], 'each input: one input beyond both')]
    #[DataSet([[[100.0, 90.0], [100.0, 90.0], [100.0, 120.0]], [[50.0, 45.0]], false, null], 'the mean: one input beyond both is not enough')]
    #[DataSet([[[100.0, 90.0]], [[400.0, 430.0], [400.0, 430.0]], false, 'costs more with the tracing JIT: +30 instructions per call on average (+7.5%), 2 of 2 inputs beyond guard_cost (5% and 15 instructions)'], 'the mean with the JIT')]
    #[DataSet([[[0.0, 20.0]], [[50.0, 45.0]], false, 'costs more without the JIT: +20 instructions per call on average (from nothing), 1 of 1 inputs beyond guard_cost (5% and 15 instructions)'], 'from nothing')]
    public function rejectsOnlyWhatGrowsBeyondBothThresholds(array $off, array $tracing, bool $eachInput, ?string $reason): void
    {
        $cost = new Cost([Cost::OFF => $off, Cost::TRACING => $tracing]);

        Assert::same($cost->regression(5, 15, $eachInput), $reason);
    }

    public function meanOfEachModeForTheReport(): void
    {
        $cost = new Cost([Cost::OFF => [[100.0, 80.0], [300.0, 240.0]], Cost::TRACING => [[0.0, 0.0]]]);

        Assert::same($cost->toArray(), [
            'off' => ['before' => 200.0, 'after' => 160.0, 'change_percent' => -20.0, 'inputs' => 2],
            'tracing' => ['before' => 0.0, 'after' => 0.0, 'change_percent' => null, 'inputs' => 1],
        ]);
    }
}
