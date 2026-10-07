<?php

declare(strict_types=1);

namespace Opmin\Command;

use Internal\Path;
use Opmin\Module\Config\Schema;
use Opmin\Module\Php\PhpBinaryException;
use Opmin\Module\Php\PhpBinaryProbe;
use Opmin\Module\Project\Project;
use Opmin\Module\Verification\CandidateReport;
use Opmin\Module\Verification\Verifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verify a changed version of a file: the checks every optimization step goes through (brief,
 * Модуль 2). The file is not changed (with `--with-tests` the candidate is written in place for
 * PHPStan and the tests and the original is restored). Counterexamples of rejected functions are
 * written as tests of the project's runner to `runs/<timestamp>/counterexamples/`.
 *
 * Exit codes: 0 — the candidate is accepted; 1 — rejected (or the project's tests fail on the
 * original code); 2 — invalid config, missing files, unusable `php.binary`.
 *
 * @internal
 */
#[AsCommand(
    name: 'verify',
    description: 'Verify that a changed version of a file behaves like the original',
)]
final class Verify extends Base
{
    public function configure(): void
    {
        parent::configure();
        $this->addArgument('file', InputArgument::REQUIRED, 'The original file (in the project)');
        $this->addArgument('candidate', InputArgument::REQUIRED, 'The changed version of the file');
        $this->addOption('function', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Function key to verify (default: every changed function)');
        $this->addOption('with-tests', null, InputOption::VALUE_NONE, 'Also run PHPStan and the project\'s tests (writes the candidate in place for the run)');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table | json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $style = new SymfonyStyle($input, $errorOutput);
        $format = (string) $input->getOption('format');
        if (!\in_array($format, ['table', 'json'], true)) {
            $style->error("Unknown format `{$format}`: use table or json.");
            return Command::INVALID;
        }

        $cwd = Path::create((string) \getcwd());
        $file = Path::create((string) $input->getArgument('file'))->absolute((string) $cwd);
        $candidate = Path::create((string) $input->getArgument('candidate'))->absolute((string) $cwd);
        foreach ([$file, $candidate] as $path) {
            if (!$path->isFile()) {
                $style->error("File `{$path}` does not exist.");
                return Command::INVALID;
            }
        }

        /** @var Schema\Php $phpConfig */
        $phpConfig = $this->container->get(Schema\Php::class);
        /** @var Schema\Cache $cacheConfig */
        $cacheConfig = $this->container->get(Schema\Cache::class);
        try {
            $php = (new PhpBinaryProbe())->probe($phpConfig->binary);
        } catch (PhpBinaryException $e) {
            $style->error($e->getMessage());
            return Command::INVALID;
        }

        $project = Project::detect($file, $cwd);
        $cacheDir = Path::create($cacheConfig->dir);
        $cacheDir->isAbsolute() or $cacheDir = $project->root->join($cacheConfig->dir);
        /** @var Schema\Verification $verification */
        $verification = $this->container->get(Schema\Verification::class);
        /** @var Schema\Commands $commands */
        $commands = $this->container->get(Schema\Commands::class);
        /** @var Schema\Tests $tests */
        $tests = $this->container->get(Schema\Tests::class);
        $verifier = new Verifier(
            $project,
            $php,
            $verification,
            $commands,
            $tests,
            $cacheDir,
            $phpConfig->target ?? $project->phpTarget,
            static function (string $message) use ($errorOutput): void {
                $errorOutput->isVerbose() and $errorOutput->writeln("  {$message}");
            },
        );

        /** @var list<non-empty-string> $keys */
        $keys = \array_values(\array_filter((array) $input->getOption('function'), static fn(mixed $k): bool => \is_string($k) && $k !== ''));
        try {
            $report = $verifier->verify(
                $file,
                (string) \file_get_contents((string) $candidate),
                $keys === [] ? null : $keys,
                (bool) $input->getOption('with-tests'),
                $project->root->join('runs', \date('Ymd-His'), 'counterexamples'),
            );
        } catch (\InvalidArgumentException $e) {
            $style->error($e->getMessage());
            return Command::INVALID;
        }

        $format === 'json'
            ? $output->write((string) \json_encode($report->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION) . "\n", false, OutputInterface::OUTPUT_RAW)
            : $this->table($report, new SymfonyStyle($input, $output));

        return $report->accepted() ? Command::SUCCESS : Command::FAILURE;
    }

    private function table(CandidateReport $report, SymfonyStyle $style): void
    {
        $report->syntaxError === null or $style->error('Syntax error: ' . $report->syntaxError);
        foreach ($report->phpstan as $error) {
            $style->error("New PHPStan error in {$error['file']}:{$error['line']}: {$error['message']}");
        }

        if ($report->tests !== null) {
            $report->tests->success
                ? $style->writeln("Project tests ({$report->runner}): <info>{$report->tests->tests} passed</info>")
                : $style->error("Project tests ({$report->runner}) fail: " . \implode(', ', \array_slice($report->tests->failed, 0, 10)));
        }

        $rows = [];
        foreach ($report->functions as $function) {
            $verdict = $function->verdict;
            $rows[] = [
                $function->key,
                $function->accepted() ? "<info>{$function->status}</info>" : "<error>{$function->status}</error>",
                $verdict === null ? '' : \sprintf('%s%%', $verdict->coverage),
                $verdict === null ? '' : (string) $verdict->inputs,
                $function->reason . ($function->counterexampleTest === null ? '' : "\nTest: {$function->counterexampleTest}"),
            ];
        }

        $rows === [] or $style->table(['Function', 'Status', 'Coverage', 'Inputs', 'Reason'], $rows);
        $report->notes === [] or $style->note($report->notes);
        $report->accepted()
            ? $style->success('The candidate is accepted.')
            : $style->warning('The candidate is rejected.');
    }
}
