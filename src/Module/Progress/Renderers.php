<?php

declare(strict_types=1);

namespace Opmin\Module\Progress;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Picks the renderer of the progress for stderr: `-v` — the detailed lines; a terminal
 * (`isDecorated()`, like the progress bar of `count`) — the line redrawn in place; otherwise
 * (CI, a pipe, `--no-ansi`) — the lines with timestamps; `-q` — nothing.
 *
 * @internal
 */
final class Renderers
{
    public static function for(OutputInterface $errorOutput, Clock $clock = new Clock()): Renderer
    {
        return match (true) {
            $errorOutput->isQuiet() => new Silent(),
            $errorOutput->isVerbose() => new TimedLines($errorOutput, true, $clock),
            $errorOutput->isDecorated() => new LiveLine($errorOutput, $clock),
            default => new TimedLines($errorOutput, false, $clock),
        };
    }

    /**
     * Lines only (a command an agent runs, whose output is read afterwards); the detailed ones with `-v`.
     */
    public static function lines(OutputInterface $errorOutput, Clock $clock = new Clock()): Renderer
    {
        return $errorOutput->isQuiet() ? new Silent() : new TimedLines($errorOutput, $errorOutput->isVerbose(), $clock);
    }
}
