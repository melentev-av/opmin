<?php

declare(strict_types=1);

namespace Opmin\Command;

use Opmin\Module\Runs\RunIndex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints the report of a run: the given one, or the newest run that has a report (an interrupted run, a
 * verify run or a broken one has none). Nothing is opened in a viewer.
 *
 * ```bash
 * opmin runs:show                      # report.md of the newest run with a report
 * opmin runs:show 20261011-062031 --format=json
 * opmin runs:show --format=path        # where report.md is
 * ```
 *
 * `--format`: `md` — `report.md` as it is, `json` — `report.json` as it is (stable: `"schema": 1`, fields are
 * only added), `path` — the absolute path of `report.md`.
 *
 * Exit codes: 0 — shown; 2 — no such run, no report in it, or invalid usage.
 *
 * @internal
 */
#[AsCommand(
    name: 'runs:show',
    description: 'Print the report of a run (default: the newest one with a report)',
)]
final class RunsShow extends RunsBase
{
    public function configure(): void
    {
        parent::configure();
        $this->addArgument('id', InputArgument::OPTIONAL, 'The run: the name of its directory in .opmin/runs (default: the newest with a report)');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output: md | json | path', 'md');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $style = new SymfonyStyle($input, $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);
        $format = $input->getOption('format');
        if (!\in_array($format, ['md', 'json', 'path'], true)) {
            $style->error('--format takes md, json or path.');
            return Command::INVALID;
        }

        /** @var string|null $id */
        $id = $input->getArgument('id');
        # A path pasted from the summary of a run (`.opmin/runs/<id>/`) names the run too.
        $id === null or $id = \basename(\rtrim(\str_replace('\\', '/', $id), '/'));
        $run = RunIndex::find($this->runs(), $id);
        if ($run === null) {
            $style->error($id === null
                ? 'No run with a report in ' . $this->relative($this->runsDir()) . '/: `opmin runs` lists the runs.'
                : "No run {$id} in " . $this->relative($this->runsDir()) . '/: `opmin runs` lists the runs.');
            return Command::INVALID;
        }

        if (!$run->report) {
            $style->error("The run {$run->id} has no report ({$run->kind->value}, {$run->status->value}).");
            return Command::INVALID;
        }

        $file = $run->dir->join($format === 'json' ? 'report.json' : 'report.md');
        if ($format === 'path') {
            $output->writeln((string) $file, OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        }

        $content = @\file_get_contents((string) $file);
        if ($content === false) {
            $style->error("{$file} cannot be read.");
            return Command::INVALID;
        }

        $output->write($content, false, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
