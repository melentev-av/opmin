<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Progress;

use Opmin\Module\Progress\Clock;
use Opmin\Module\Progress\Event;
use Opmin\Module\Progress\LiveLine;
use Opmin\Module\Progress\Renderers;
use Opmin\Module\Progress\Silent;
use Opmin\Module\Progress\TimedLines;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The progress as lines with timestamps (a log without a terminal, `-v`), and the choice of the renderer.
 */
#[Test]
#[Covers(TimedLines::class)]
#[Covers(Renderers::class)]
#[Covers(Event::class)]
final class TimedLinesTest
{
    private const float START = 1_700_000_000.0;

    private float $now = self::START;

    public function linesHaveTheTimeAndTheSecondsSinceTheStartAndEveryStepItsResult(): void
    {
        $this->now = self::START;
        $output = new BufferedOutput();
        $lines = new TimedLines($output, false, new Clock(fn(): float => $this->now));

        $lines->on(Event::counting(3));
        $lines->on(Event::originalTests('phpunit'));
        $this->now += 5;
        $lines->on(Event::pass(1, 'FullyQualifyGlobalCallsRector'));
        $lines->on(Event::rector(3));
        $this->now += 2;
        $lines->on(Event::verifying(2, 1));
        $lines->on(Event::coverageMap());
        $lines->on(Event::warning('No coverage map of the project\'s tests: every step runs the whole test suite'));
        $lines->on(Event::phpStan());
        $lines->on(Event::diffTests(2, 1));
        $lines->on(Event::diffTest('App\a', 0, 2));
        $lines->on(Event::diffDone(1, 2));
        $lines->on(Event::verifying(1, 2));
        $this->now += 70;
        $lines->on(Event::stepDone(1, 'FullyQualifyGlobalCallsRector', 3, 1, 12, null));
        $lines->on(Event::pass(1, 'Other'));
        $lines->on(Event::stepDone(1, 'Other', 0, 0, 0, null));
        $lines->on(Event::finalTests(1));

        $at = static fn(int $s): string => '[' . \date('H:i:s', (int) self::START + $s) . " +{$s}s]";
        Assert::same($output->fetch(), \implode("\n", [
            "  {$at(0)} Counting the opcodes of 3 file(s)",
            "  {$at(0)} Project tests on the original code (phpunit)",
            "  {$at(5)} Pass 1: FullyQualifyGlobalCallsRector",
            "  {$at(7)} Verifying 2 file(s)",
            "  {$at(7)} Coverage map of the project's tests",
            "  {$at(7)} Warning: No coverage map of the project's tests: every step runs the whole test suite",
            "  {$at(7)} Verifying 1 file(s), round 2",
            "  {$at(77)} Pass 1: FullyQualifyGlobalCallsRector: kept 3, rolled back 1, -12 opcodes (1m12s)",
            "  {$at(77)} Pass 1: Other",
            "  {$at(77)} Pass 1: Other: no change (0s)",
            "  {$at(77)} Final run of the project's tests",
            '',
        ]));
    }

    public function verboseLinesShowThePhasesInsideAVerification(): void
    {
        $this->now = self::START;
        $output = new BufferedOutput();
        $lines = new TimedLines($output, true, new Clock(fn(): float => $this->now));

        $lines->on(Event::rector(3));
        $lines->on(Event::phpStan());
        $lines->on(Event::tests(4));
        $lines->on(Event::diffTests(2, 2));
        $lines->on(Event::diffTest('App\a', 0, 2));
        $lines->on(Event::diffDone(1, 2));

        $at = '[' . \date('H:i:s', (int) self::START) . ' +0s]';
        Assert::same($output->fetch(), \implode("\n", [
            "  {$at} Rector on 3 file(s)",
            "  {$at} PHPStan on the changed code",
            "  {$at} Project tests on the changed code: 4 that execute the changed functions",
            "  {$at} Differential tests of 2 functions, 2 at a time",
            "  {$at} Differential test of App\\a",
            '',
        ]));
    }

    public function aTerminalGetsTheLiveLineALogTheLinesAndVerboseTheDetailedLines(): void
    {
        Assert::instanceOf(Renderers::for(new BufferedOutput(decorated: true)), LiveLine::class);
        Assert::instanceOf(Renderers::for(new BufferedOutput(decorated: false)), TimedLines::class);
        Assert::instanceOf(Renderers::for(new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE, decorated: true)), TimedLines::class);
        Assert::instanceOf(Renderers::for(new BufferedOutput(OutputInterface::VERBOSITY_QUIET, decorated: true)), Silent::class);
        Assert::instanceOf(Renderers::lines(new BufferedOutput(decorated: true)), TimedLines::class);
    }
}
