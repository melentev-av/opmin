<?php

declare(strict_types=1);

namespace Opmin\Command;

use Internal\Path;
use Opmin\Module\Config\Schema;
use Opmin\Module\Doctor\Check;
use Opmin\Module\Doctor\Doctor as Checks;
use Opmin\Module\Doctor\Status;
use Opmin\Module\Release\Installation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks the environment before the first run and in CI: `php.binary` (first — nothing works without it),
 * OPcache and the harness under it, the PHP version against `php.target`, files with syntax newer than
 * `php.binary`, the coverage driver, the test runner, PHPStan, git, the formatter. Prints what is wrong and
 * how to fix it.
 *
 * ```bash
 * opmin doctor
 * opmin doctor --with-tests   # also run the project's test suite once
 * ```
 *
 * Exit codes: 0 — nothing blocks `count`/`optimize` (warnings allowed); 1 — something does.
 *
 * @internal
 */
#[AsCommand(
    name: 'doctor',
    description: 'Check the environment: php.binary, OPcache, git, tests, PHPStan',
)]
final class Doctor extends Base
{
    private const MARKS = [
        'ok' => '<info>✔</info>',
        'warning' => '<comment>!</comment>',
        'error' => '<error>✘</error>',
        'info' => '<fg=blue>i</>',
        'skipped' => '<fg=gray>-</>',
    ];

    public function configure(): void
    {
        parent::configure();
        $this->addOption('with-tests', null, InputOption::VALUE_NONE, 'Also run the project\'s test suite once');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        /** @var Schema\Php $php */
        $php = $this->container->get(Schema\Php::class);
        /** @var Schema\Project $project */
        $project = $this->container->get(Schema\Project::class);
        /** @var Schema\Tests $tests */
        $tests = $this->container->get(Schema\Tests::class);
        /** @var Schema\Commands $commands */
        $commands = $this->container->get(Schema\Commands::class);

        $checks = (new Checks(
            Path::create((string) \getcwd()),
            $php,
            $project,
            $tests,
            $commands,
            Installation::current(),
            (bool) $input->getOption('with-tests'),
        ))->run();

        foreach ($checks as $check) {
            $this->print($output, $check);
        }

        $errors = \count(\array_filter($checks, static fn(Check $c): bool => $c->status === Status::Error));
        $warnings = \count(\array_filter($checks, static fn(Check $c): bool => $c->status === Status::Warning));
        $output->writeln('');
        $output->writeln(match (true) {
            $errors > 0 => "<error>{$errors} problem(s) block opmin, {$warnings} warning(s).</error>",
            $warnings > 0 => "<comment>Ready, with {$warnings} warning(s).</comment>",
            default => '<info>Ready: opmin count, then opmin optimize.</info>',
        });

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function print(OutputInterface $output, Check $check): void
    {
        $lines = \explode("\n", $check->message);
        $output->writeln(\sprintf(' %s <options=bold>%s</>: %s', self::MARKS[$check->status->value], $check->name, \array_shift($lines)));
        foreach ($lines as $line) {
            $output->writeln('     ' . $line);
        }

        $check->fix === null or $output->writeln('     <comment>Fix:</comment> ' . $check->fix);
    }
}
