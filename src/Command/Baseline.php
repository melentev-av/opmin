<?php

declare(strict_types=1);

namespace Opmin\Command;

use Internal\Path;
use Opmin\Module\Check\Baseline as BaselineFile;
use Opmin\Module\Config\Schema;
use Opmin\Module\Php\PhpBinaryException;
use Opmin\Module\Php\PhpBinaryProbe;
use Opmin\Module\Project\Targets;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Write opmin.baseline.json with the current opcode counts of the project (`paths` of the config),
 * for `opmin check`.
 *
 * Exit codes: 0 — written; 1 — some files could not be counted (nothing is written: a baseline
 * without them would report all their functions as new); 2 — invalid config or `php.binary`.
 *
 * @internal
 */
#[AsCommand(
    name: 'baseline',
    description: 'Write opmin.baseline.json with the current opcode counts',
)]
final class Baseline extends Stage
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $style = new SymfonyStyle($input, $errorOutput);

        /** @var Schema\Php $phpConfig */
        $phpConfig = $this->container->get(Schema\Php::class);
        /** @var Schema\Project $projectConfig */
        $projectConfig = $this->container->get(Schema\Project::class);
        try {
            $php = (new PhpBinaryProbe())->probe($phpConfig->binary);
            [$project, $paths] = Targets::resolve([], Path::create((string) \getcwd()), $projectConfig);
            $files = $this->files($project, $paths);
        } catch (PhpBinaryException|\InvalidArgumentException $e) {
            $style->error($e->getMessage());
            return Command::INVALID;
        }

        $report = $this->countReport($project, $php, $files);
        if ($report->errors !== []) {
            $style->error(\array_merge(
                [\sprintf('%d file(s) could not be counted, %s is not written:', \count($report->errors), BaselineFile::FILE)],
                \array_map(static fn(string $file, string $error): string => "{$file}: {$error}", \array_keys($report->errors), $report->errors),
            ));
            return Command::FAILURE;
        }

        $baseline = BaselineFile::fromReport($report);
        $baseline->write($project->root->join(BaselineFile::FILE));
        $style->success(\sprintf(
            'Wrote %s: %d functions of %d files, PHP %s, optimizer %s. Commit it.',
            BaselineFile::FILE,
            \count($baseline->functions),
            $report->files,
            $php->version,
            $report->optimizerHash,
        ));

        return Command::SUCCESS;
    }
}
