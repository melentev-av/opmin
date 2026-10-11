<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Progress;

use Opmin\Module\Progress\Clock;
use Opmin\Module\Progress\Event;
use Opmin\Module\Progress\LiveLine;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The status line of a terminal: what it shows, that it is redrawn in place and taken off the screen
 * before anything else is written.
 */
#[Test]
#[Covers(LiveLine::class)]
#[Covers(Clock::class)]
final class LiveLineTest
{
    private const string CLEAR = "\r\033[2K";

    private float $now = 1_700_000_000.0;

    public function showsPassRulePhaseCounterRoundFunctionAndTime(): void
    {
        [$line, $output] = $this->line();

        $line->on(Event::pass(2, 'FullyQualifyGlobalCallsRector'));
        $line->on(Event::verifying(3, 2));
        $line->on(Event::diffTests(47, 4));
        $line->on(Event::diffTest('App\Text::size', 11, 47));
        $this->now += 192;
        $line->on(Event::diffDone(12, 47));

        $status = 'pass 2 · FullyQualifyGlobalCallsRector · verifying 12/47 · round 2 · App\Text::size · 3m12s';
        Assert::same($line->status(), $status);
        $written = $output->fetch();
        Assert::string($written)->endsWith(self::CLEAR . $status);
        # Redrawn in place: no line break, one clearing sequence per event.
        Assert::string($written)->notContains("\n");
        Assert::same(\substr_count($written, self::CLEAR), 5);
    }

    public function aNewPhaseDropsTheCounterOfTheOneBefore(): void
    {
        [$line] = $this->line();

        $line->on(Event::pass(1, 'R'));
        $line->on(Event::diffTests(5, 1));
        $line->on(Event::diffTest('App\a', 0, 5));
        $line->on(Event::phpStan());

        Assert::same($line->status(), 'pass 1 · R · phpstan · 0s');
    }

    public function clearTakesTheLineOffOnceAndOnlyWhenItIsDrawn(): void
    {
        [$line, $output] = $this->line();

        $line->clear();
        Assert::same($output->fetch(), '');
        $line->on(Event::originalTests('phpunit'));
        $output->fetch();
        $line->clear();
        $line->clear();

        Assert::same($output->fetch(), self::CLEAR);
    }

    public function theResultOfAStepStaysAsALineAboveTheStatus(): void
    {
        [$line, $output] = $this->line();

        $line->on(Event::pass(1, 'R'));
        $this->now += 12;
        $line->on(Event::stepDone(1, 'R', 2, 1, 5, null));
        $line->clear();

        Assert::same($output->fetch(), self::CLEAR . 'pass 1 · R · rector · 0s' . self::CLEAR . "  Pass 1: R: kept 2, rolled back 1, -5 opcodes (12s)\n");
    }

    public function aWarningIsALineAndTheStatusIsDrawnAgain(): void
    {
        [$line, $output] = $this->line();

        $line->on(Event::coverageMap());
        $output->fetch();
        $line->on(Event::warning('No coverage map'));

        Assert::same($output->fetch(), self::CLEAR . "  Warning: No coverage map\n" . self::CLEAR . 'coverage map of the tests · 0s');
    }

    public function theLineIsCutToTheWidthOfTheTerminal(): void
    {
        [$line, $output] = $this->line(20);

        $line->on(Event::pass(1, 'AVeryLongRuleNameThatDoesNotFit'));

        Assert::same($output->fetch(), self::CLEAR . 'pass 1 · AVeryLong…');
    }

    /**
     * @param positive-int $width
     * @return array{LiveLine, BufferedOutput}
     */
    private function line(int $width = 200): array
    {
        $output = new BufferedOutput(decorated: false);

        return [new LiveLine($output, new Clock(fn(): float => $this->now), $width), $output];
    }
}
