<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize\Review;

use Symfony\Component\Console\Exception\MissingInputException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The review in the terminal: the diff of the change, its gain and checks, then `y/n/a/q`.
 *
 * When the input ends (a pipe, a closed stdin), the remaining changes are applied without asking, as
 * without `--review`.
 *
 * @internal
 */
final class ConsoleReviewer implements Reviewer
{
    private bool $ended = false;

    public function __construct(
        private readonly SymfonyStyle $style,
    ) {}

    public function review(Change $change): Decision
    {
        if ($this->ended) {
            return Decision::Yes;
        }

        $this->style->newLine();
        $this->style->writeln(\sprintf('<info>%s</info> — %s', OutputFormatter::escape(\implode(', ', $change->functions)), $change->file));
        foreach (\explode("\n", \rtrim($change->diff)) as $line) {
            $escaped = OutputFormatter::escape($line);
            $this->style->writeln(match ($line[0] ?? '') {
                '+' => "<fg=green>{$escaped}</>",
                '-' => "<fg=red>{$escaped}</>",
                '@' => "<fg=cyan>{$escaped}</>",
                default => $escaped,
            });
        }

        $this->style->writeln(OutputFormatter::escape($change->summary()));
        $question = new Question('Apply? [y]es, [n]o (never propose again), [a]ll of this rule, [q]uit', 'y');
        $question->setValidator(static function (mixed $answer): Decision {
            $answer = \strtolower(\trim(\is_string($answer) ? $answer : ''));
            return Decision::tryFrom($answer === '' ? 'y' : $answer[0]) ?? throw new \InvalidArgumentException('Answer y, n, a or q.');
        });

        try {
            /** @var Decision */
            return $this->style->askQuestion($question);
        } catch (MissingInputException) {
            $this->ended = true;
            $this->style->warning('The input ended: the remaining changes are applied without asking.');

            return Decision::Yes;
        }
    }
}
