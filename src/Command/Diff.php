<?php

declare(strict_types=1);

namespace Opmin\Command;

use Opmin\Module\Opcode\Report\CountReport;
use Opmin\Module\Opcode\Report\ReportDiff;
use Opmin\Module\Opcode\Report\ReportException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Compare two count reports function by function.
 *
 * Exit codes: 0 — compared; 2 — a report cannot be read, or the reports were taken with different
 * PHP versions or optimizer settings and are not comparable.
 *
 * @internal
 */
#[AsCommand(
    name: 'diff',
    description: 'Compare two count reports function by function',
)]
final class Diff extends Base
{
    public function configure(): void
    {
        parent::configure();
        $this->addArgument('before', InputArgument::REQUIRED, 'Report before (JSON of `opmin count --format=json`)');
        $this->addArgument('after', InputArgument::REQUIRED, 'Report after (JSON)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $style = new SymfonyStyle($input, $output);

        try {
            $diff = ReportDiff::compare(
                $this->read((string) $input->getArgument('before')),
                $this->read((string) $input->getArgument('after')),
            );
        } catch (ReportException $e) {
            $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            (new SymfonyStyle($input, $error))->error($e->getMessage());
            return Command::INVALID;
        }

        if ($diff->warnings !== []) {
            $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            (new SymfonyStyle($input, $error))->warning($diff->warnings);
        }

        /** @param list<array{key: non-empty-string, before: int, after: int, delta: int}> $items */
        $rows = static fn(array $items): array => \array_map(
            /** @param array{key: non-empty-string, before: int, after: int, delta: int} $r */
            static fn(array $r): array => [$r['key'], $r['before'], $r['after'], \sprintf('%+d', $r['delta'])],
            $items,
        );

        if ($diff->better !== []) {
            $style->section('Fewer opcodes');
            $style->table(['Function', 'Before', 'After', 'Delta'], $rows($diff->better));
        }

        if ($diff->worse !== []) {
            $style->section('More opcodes');
            $style->table(['Function', 'Before', 'After', 'Delta'], $rows($diff->worse));
        }

        foreach (['New functions' => $diff->added, 'Removed functions' => $diff->removed] as $title => $items) {
            if ($items !== []) {
                $style->section($title);
                $style->table(['Function', 'Opcodes'], \array_map(null, \array_keys($items), \array_values($items)));
            }
        }

        $delta = $diff->totalAfter - $diff->totalBefore;
        $style->writeln(\sprintf(
            'Total: %d → %d opcodes (%+d, %s); %d fewer, %d more, %d unchanged, %d new, %d removed.',
            $diff->totalBefore,
            $diff->totalAfter,
            $delta,
            $diff->totalBefore === 0 ? 'n/a' : \sprintf('%+.1f%%', $delta * 100 / $diff->totalBefore),
            \count($diff->better),
            \count($diff->worse),
            $diff->unchanged,
            \count($diff->added),
            \count($diff->removed),
        ));

        return Command::SUCCESS;
    }

    /**
     * @throws ReportException
     */
    private function read(string $file): CountReport
    {
        $json = @\file_get_contents($file);
        $json === false and throw new ReportException("Cannot read the report `{$file}`.");

        return CountReport::fromJson($json, $file);
    }
}
