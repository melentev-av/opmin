<?php

declare(strict_types=1);

namespace Opmin\Command;

use Opmin\Module\Runs\Run;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists the runs in `.opmin/runs/`, newest first: id, kind, state, opcodes before → after (the totals of
 * `report.json`) and size.
 *
 * ```bash
 * opmin runs
 * opmin runs --format=json
 * ```
 *
 * The JSON is `{"runs": [{id, kind, state, ops_before, ops_after, size, report, backup, path}]}`: the field
 * names are fixed, new ones are only added.
 *
 * Exit codes: 0 — listed (also when there are none); 2 — invalid usage.
 *
 * @internal
 */
#[AsCommand(
    name: 'runs',
    description: 'List the runs in .opmin/runs: kind, state, opcodes, size',
)]
final class Runs extends RunsBase
{
    public function configure(): void
    {
        parent::configure();
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output: table | json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $format = $input->getOption('format');
        if ($format !== 'table' && $format !== 'json') {
            $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            (new SymfonyStyle($input, $error))->error('--format takes table or json.');
            return Command::INVALID;
        }

        $runs = $this->runs();
        if ($format === 'json') {
            $output->write(\json_encode(
                ['runs' => \array_map(fn(Run $run): array => [
                    'id' => $run->id,
                    'kind' => $run->kind->value,
                    'state' => $run->status->value,
                    'ops_before' => $run->opsBefore,
                    'ops_after' => $run->opsAfter,
                    'size' => $run->size,
                    'report' => $run->report,
                    'backup' => $run->backup,
                    'path' => $this->relative($run->dir),
                ], $runs)],
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
            ) . "\n", false, OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        $style = new SymfonyStyle($input, $output);
        if ($runs === []) {
            $style->writeln('No runs in ' . $this->relative($this->runsDir()) . '.');
            return Command::SUCCESS;
        }

        $style->table(
            ['Run', 'Kind', 'State', 'Opcodes', 'Size'],
            \array_map(static fn(Run $run): array => [
                $run->id,
                $run->kind->value,
                $run->status->value . ($run->backup ? ' (backup)' : ''),
                $run->opsBefore === null || $run->opsAfter === null ? '-' : "{$run->opsBefore} → {$run->opsAfter}",
                Run::bytes($run->size),
            ], $runs),
        );
        $style->writeln(\sprintf(
            '%d run(s), %s. `opmin runs:show <id>` prints a report, `opmin runs:clean --keep=N` deletes old runs.',
            \count($runs),
            Run::bytes(\array_sum(\array_map(static fn(Run $r): int => $r->size, $runs))),
        ));

        return Command::SUCCESS;
    }
}
