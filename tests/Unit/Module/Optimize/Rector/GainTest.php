<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Optimize\Rector;

use Opmin\Module\Optimize\Rector\Gain;
use Opmin\Rector\Rule\FullyQualifyGlobalCallsRector;
use Opmin\Rector\Rule\HoistLoopInvariantCountRector;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(Gain::class)]
final class GainTest
{
    public function readsTheConstantsOfARule(): void
    {
        $cost = new class {
            public const bool COST_GAIN = true;
            public const bool EXECUTED_GAIN = true;
        };

        Assert::same(Gain::of(FullyQualifyGlobalCallsRector::class), Gain::Opcodes);
        Assert::same(Gain::of(HoistLoopInvariantCountRector::class), Gain::Executed);
        # Cost decides over executed opcodes: the count does not.
        Assert::same(Gain::of($cost::class), Gain::Cost);
    }

    #[DataSet([Gain::Opcodes, 4, '-4 opcodes'], 'opcodes')]
    #[DataSet([Gain::Executed, 0, 'fewer executed opcodes'], 'executed, the same count')]
    #[DataSet([Gain::Executed, 2, '-2 opcodes'], 'executed, fewer static too')]
    #[DataSet([Gain::Cost, -1, '+1 opcodes, cheaper to run'], 'cost, grown')]
    #[DataSet([Gain::Cost, 0, 'cheaper to run'], 'cost, the same count')]
    #[DataSet([Gain::Cost, 3, '-3 opcodes'], 'cost, fewer')]
    public function describesTheGain(Gain $kind, int $gain, string $text): void
    {
        Assert::same($kind->describe($gain), $text);
    }
}
