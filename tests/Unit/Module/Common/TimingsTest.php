<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Common;

use Opmin\Module\Common\Timings;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Timings::class)]
final class TimingsTest
{
    public function addsSecondsAndCallsPerLevel(): void
    {
        $timings = new Timings();
        $timings->add('rector', 1.25);
        $timings->add('rector', 0.5);
        $timings->add('diff_test', 2.0);

        Assert::same($timings->toArray()['levels'], [
            'diff_test' => ['seconds' => 2.0, 'calls' => 1],
            'rector' => ['seconds' => 1.75, 'calls' => 2],
        ]);
    }

    public function measureReturnsTheResultAndCountsAThrowingBody(): void
    {
        $timings = new Timings();

        Assert::same($timings->measure('count', static fn(): int => 42), 42);
        try {
            $timings->measure('count', static fn(): never => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        Assert::same($timings->toArray()['levels']['count']['calls'], 2);
    }

    public function countersAddUp(): void
    {
        $timings = new Timings();
        $timings->count('verify_rounds');
        $timings->count('verify_rounds');
        $timings->count('verify_files', 3);

        Assert::same($timings->toArray()['counters'], ['verify_files' => 3, 'verify_rounds' => 2]);
    }

    public function mergeAddsTheTimingsOfAnotherProcess(): void
    {
        $timings = new Timings();
        $timings->add('diff_test.inputs', 1.0);
        $timings->count('fuzz_rounds', 2);
        $timings->merge(['levels' => ['diff_test.inputs' => ['seconds' => 0.5, 'calls' => 3], 'diff_test.start' => ['seconds' => 0.25, 'calls' => 1]], 'counters' => ['fuzz_rounds' => 5]]);

        Assert::same($timings->toArray()['levels'], [
            'diff_test.inputs' => ['seconds' => 1.5, 'calls' => 4],
            'diff_test.start' => ['seconds' => 0.25, 'calls' => 1],
        ]);
        Assert::same($timings->toArray()['counters'], ['fuzz_rounds' => 7]);
    }

    public function linesListTheSlowestLevelFirst(): void
    {
        $timings = new Timings();
        $timings->add('lint', 0.1);
        $timings->add('phpstan.after', 3.0);
        $timings->count('steps', 4);
        $lines = $timings->lines();

        Assert::string($lines[0])->contains('Time:');
        Assert::string($lines[1])->contains('phpstan.after');
        Assert::string($lines[2])->contains('lint');
        Assert::string($lines[3])->contains('steps')->contains('4');
    }
}
