<?php

declare(strict_types=1);

namespace Opmin\Module\Progress;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * The progress in a terminal: one status line on stderr, redrawn in place on every event (no timer:
 * the tools of a step are blocking processes), with the result of every step and the warnings as
 * lines above it.
 *
 * ```
 * pass 1 · FullyQualifyGlobalCallsRector · verifying 12/47 · round 2 · App\Text::size · 3m12s
 * ```
 *
 * The line is cut to the width of the terminal: a wrapped line cannot be cleared.
 *
 * @internal
 */
final class LiveLine implements Renderer
{
    private const string CLEAR = "\r\033[2K";

    private ?int $pass = null;
    private ?string $rule = null;
    private ?string $phase = null;
    private ?int $done = null;
    private ?int $total = null;
    private ?int $round = null;
    private ?string $subject = null;
    private ?float $stepStart = null;
    private bool $drawn = false;

    /**
     * @param positive-int|null $width Columns of the terminal; null — asked on every redraw.
     */
    public function __construct(
        private readonly OutputInterface $output,
        private readonly Clock $clock = new Clock(),
        private readonly ?int $width = null,
    ) {}

    #[\Override]
    public function on(Event $event): void
    {
        switch ($event->kind) {
            case EventKind::Pass:
                $this->pass = $event->pass;
                $this->rule = $event->subject;
                $this->phase('rector');
                $this->round = null;
                $this->stepStart = $this->clock->now();
                break;
            case EventKind::Rector:
                $this->phase('rector');
                break;
            case EventKind::Counting:
                $this->phase("counting {$event->total} file(s)");
                break;
            case EventKind::OriginalTests:
                $this->phase('tests on the original code');
                break;
            case EventKind::CoverageMap:
                $this->phase('coverage map of the tests');
                break;
            case EventKind::Verifying:
                $this->phase('verifying');
                $this->round = $event->round;
                break;
            case EventKind::ReviewLeft:
                $this->phase('verifying the rest after the review');
                break;
            case EventKind::PhpStan:
                $this->phase('phpstan');
                break;
            case EventKind::Tests:
                $this->phase($event->total === null ? 'tests' : "tests ({$event->total} selected)");
                break;
            case EventKind::DiffTests:
                $this->phase('verifying');
                $this->done = $event->done;
                $this->total = $event->total;
                break;
            case EventKind::DiffTest:
                $this->subject = $event->subject;
                $this->done = $event->done;
                $this->total = $event->total;
                break;
            case EventKind::DiffDone:
                $this->done = $event->done;
                $this->total = $event->total;
                break;
            case EventKind::FinalTests:
                $this->pass = null;
                $this->rule = null;
                $this->phase('final run of the tests');
                $this->round = $event->round;
                break;
            case EventKind::StepDone:
                $time = $this->stepStart === null ? '' : ' (' . Clock::format($this->clock->now() - $this->stepStart) . ')';
                $this->line(OutputFormatter::escape((string) $event->text() . $time));
                $this->pass = null;
                $this->rule = null;
                $this->phase(null);
                $this->round = null;
                $this->stepStart = null;
                return;
            case EventKind::Warning:
                $this->line('<comment>Warning: ' . OutputFormatter::escape((string) $event->subject) . '</comment>');
                break;
        }

        $this->draw();
    }

    #[\Override]
    public function clear(): void
    {
        if ($this->drawn) {
            $this->output->write(self::CLEAR, false, OutputInterface::OUTPUT_RAW);
            $this->drawn = false;
        }
    }

    /**
     * The status as it is drawn, without the clearing sequence.
     */
    public function status(): string
    {
        $parts = [];
        $this->pass === null or $parts[] = "pass {$this->pass}";
        $this->rule === null or $parts[] = $this->rule;
        $this->phase === null or $parts[] = $this->phase . ($this->total === null ? '' : " {$this->done}/{$this->total}");
        $this->round !== null && $this->round > 1 and $parts[] = "round {$this->round}";
        $this->subject === null or $parts[] = $this->subject;
        $parts[] = Clock::format($this->clock->elapsed());

        return \implode(' · ', $parts);
    }

    /**
     * A new phase: the counter and the function of the one before are gone, the round of the step stays.
     */
    private function phase(?string $phase): void
    {
        $this->phase = $phase;
        $this->done = null;
        $this->total = null;
        $this->subject = null;
    }

    /**
     * A line that stays above the status.
     */
    private function line(string $formatted): void
    {
        $this->clear();
        $this->output->writeln("  {$formatted}");
    }

    private function draw(): void
    {
        $width = $this->width ?? (new Terminal())->getWidth();
        $status = $this->status();
        Helper::width($status) < $width or $status = Helper::substr($status, 0, \max(1, $width - 2)) . '…';
        $this->output->write(self::CLEAR, false, OutputInterface::OUTPUT_RAW);
        $this->output->write(OutputFormatter::escape($status));
        $this->drawn = true;
    }
}
