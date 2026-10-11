<?php

declare(strict_types=1);

namespace Opmin\Command;

use Opmin\Module\Project\Layout;
use Opmin\Module\Runs\Run;
use Opmin\Module\Runs\RunCleaner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes old runs of `.opmin/runs/` with their copies of the report `.opmin/report_<id>.md`. Kept: the
 * `--keep` newest finished runs, every interrupted optimize run (each can be continued with
 * `--resume=<dir>`), every open LLM session, and — unless `--include-backups` — the runs whose `original/`
 * is the only copy of the code they changed (a run outside git that was not a dry run). An explicit command:
 * it prints what it deletes and asks nothing; `--dry-run` only prints.
 *
 * ```bash
 * opmin runs:clean --keep=5 --dry-run
 * opmin runs:clean --keep=0 --include-backups
 * ```
 *
 * Exit codes: 0 — done; 1 — some runs could not be deleted; 2 — invalid usage.
 *
 * @internal
 */
#[AsCommand(
    name: 'runs:clean',
    description: 'Delete old runs in .opmin/runs, keeping the newest, interrupted runs and open LLM sessions',
)]
final class RunsClean extends RunsBase
{
    public function configure(): void
    {
        parent::configure();
        $this->addOption('keep', null, InputOption::VALUE_REQUIRED, 'How many of the newest finished runs to keep');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would be deleted, delete nothing');
        $this->addOption('include-backups', null, InputOption::VALUE_NONE, 'Also delete runs whose original/ is the only copy of the code (outside git)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $errors = new SymfonyStyle($input, $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);
        /** @var mixed $keep */
        $keep = $input->getOption('keep');
        $keep = $keep === null ? false : \filter_var($keep, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($keep === false) {
            $errors->error('--keep takes how many of the newest finished runs to keep: 0 or more, e.g. --keep=5.');
            return Command::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $runs = $this->runs();
        $plan = RunCleaner::plan($runs, $keep, (bool) $input->getOption('include-backups'));
        $style = new SymfonyStyle($input, $output);
        foreach ($plan->delete as $run) {
            $copy = Layout::report($run->dir);
            $style->writeln(\sprintf(
                '%s %s (%s, %s, %s)%s',
                $dryRun ? 'Would delete' : 'Deleting',
                $this->relative($run->dir),
                $run->kind->value,
                $run->status->value,
                Run::bytes($run->size),
                $copy !== null && $copy->isFile() ? ' and ' . $this->relative($copy) : '',
            ));
        }

        foreach ($plan->backups as $run) {
            $style->writeln("Keeping {$this->relative($run->dir)}: its original/ is the only copy of the code it changed (not under git); --include-backups deletes it.");
        }

        $failed = $dryRun ? [] : RunCleaner::apply($plan);
        foreach ($failed as $failure) {
            $errors->error("Not deleted: {$failure}");
        }

        $freed = \array_sum(\array_map(static fn(Run $r): int => $r->size, $plan->delete));
        $style->writeln(\sprintf(
            '%s %d of %d run(s), %s; kept %d.',
            $dryRun ? 'Would delete' : 'Deleted',
            \count($plan->delete) - \count($failed),
            \count($runs),
            Run::bytes($freed),
            \count($runs) - \count($plan->delete) + \count($failed),
        ));

        return $failed === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
