<?php

declare(strict_types=1);

namespace Opmin\Module\Progress;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The progress as lines, for a log without a terminal (CI, a pipe) and for `-v`: each line with the
 * wall clock and the seconds since the run started, a line with the result and the time of every step.
 * Without `-v` the phases inside a verification (PHPStan, the tests, each differential test) make no line.
 *
 * ```
 *   [14:02:11 +83s] Pass 1: FullyQualifyGlobalCallsRector
 *   [14:02:19 +91s] Verifying 3 file(s)
 *   [14:03:02 +134s] Pass 1: FullyQualifyGlobalCallsRector: kept 4, rolled back 1, -12 opcodes (51s)
 * ```
 *
 * @internal
 */
final class TimedLines implements Renderer
{
    private ?float $stepStart = null;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly bool $verbose,
        private readonly Clock $clock = new Clock(),
    ) {}

    #[\Override]
    public function on(Event $event): void
    {
        $text = $event->text();
        if ($text === null || $event->detailed() && !$this->verbose) {
            return;
        }

        $event->kind === EventKind::Pass and $this->stepStart = $this->clock->now();
        if ($event->kind === EventKind::StepDone && $this->stepStart !== null) {
            $text .= ' (' . Clock::format($this->clock->now() - $this->stepStart) . ')';
            $this->stepStart = null;
        }

        $line = \sprintf('[%s +%ds] ', $this->clock->time(), (int) \floor($this->clock->elapsed()));
        $text = OutputFormatter::escape($text);
        $this->output->writeln($event->kind === EventKind::Warning
            ? "  {$line}<comment>Warning: {$text}</comment>"
            : "  {$line}{$text}");
    }

    #[\Override]
    public function clear(): void {}
}
