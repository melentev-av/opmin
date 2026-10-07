<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification\Input;

use Opmin\Module\Verification\Input;
use Opmin\Module\Verification\Input\Feedback;
use Opmin\Module\Verification\Input\Recipes;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Feedback::class)]
final class FeedbackTest
{
    public function branchProbesAreCountedAndMissed(): void
    {
        $feedback = new Feedback(3);

        Assert::same($feedback->missed(), [0, 1, 2]);
        Assert::true($feedback->record(self::input(1), [0, 2]));
        Assert::false($feedback->record(self::input(2), [2]));
        Assert::true($feedback->record(self::input(3), [2, 7]));

        Assert::same($feedback->covered(), 2);
        Assert::same($feedback->missed(), [1]);
        Assert::same($feedback->percent(), 66.7);
        Assert::false($feedback->complete());

        Assert::true($feedback->record(self::input(4), [1]));
        Assert::same($feedback->percent(), 100.0);
        Assert::true($feedback->complete());
    }

    public function onlyInputsThatOpenBranchesAreKept(): void
    {
        $feedback = new Feedback(100);
        for ($i = 0; $i < 70; ++$i) {
            $feedback->record(self::input($i), [$i]);
            $feedback->record(self::input(-$i - 1), [$i]);
        }

        $kept = \array_map(static fn(Input $input): mixed => $input->args[0]['value'], $feedback->pool());
        Assert::same($kept, \range(6, 69));
    }

    public function functionWithoutProbesIsComplete(): void
    {
        $feedback = new Feedback(0);

        Assert::same($feedback->missed(), []);
        Assert::same($feedback->percent(), 100.0);
        Assert::true($feedback->complete());
        Assert::false($feedback->record(self::input(1), []));
    }

    public function linesReplaceTheProbesOnResize(): void
    {
        $feedback = new Feedback(0);
        $feedback->record(self::input(1), [10, 99]);
        $feedback->resize([10, 11, 12]);

        Assert::same($feedback->probes(), 3);
        Assert::same($feedback->covered(), 1);
        Assert::same($feedback->missed(), [11, 12]);
        Assert::same($feedback->percent(), 33.3);
    }

    private static function input(int $value): Input
    {
        return new Input([Recipes::int($value)]);
    }
}
