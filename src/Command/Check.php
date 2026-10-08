<?php

declare(strict_types=1);

namespace Opmin\Command;

use Internal\Path;
use Opmin\Info;
use Opmin\Module\Check\Baseline as BaselineFile;
use Opmin\Module\Check\BaselineException;
use Opmin\Module\Check\ChangedFiles;
use Opmin\Module\Check\Comparison;
use Opmin\Module\Check\Finding;
use Opmin\Module\Check\FindingKind;
use Opmin\Module\Check\Format;
use Opmin\Module\Check\Renderer;
use Opmin\Module\Check\Suggester;
use Opmin\Module\Config\Schema;
use Opmin\Module\Opcode\OptimizerSettings;
use Opmin\Module\Optimize\Rector\RectorRunner;
use Opmin\Module\Optimize\Rector\RuleCatalog;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Php\PhpBinaryException;
use Opmin\Module\Php\PhpBinaryProbe;
use Opmin\Module\Project\Project;
use Opmin\Module\Project\Targets;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fail when opcodes grew compared to the baseline (`opmin.baseline.json`).
 *
 * By default only the files changed relative to `check.base_ref` (`--base=`) are checked, `--all`
 * checks the whole project. The report goes to stdout in the chosen format, messages to stderr.
 *
 * Exit codes: 0 — no function grew beyond `check.tolerance` (decreased and new ones are reported);
 * 1 — opcodes grew, or a checked file could not be counted; 2 — the baseline is missing or was
 * taken with another PHP minor version / optimizer settings (rebuild it, nothing is compared), the
 * changed files cannot be determined, or invalid config or usage.
 *
 * @internal
 */
#[AsCommand(
    name: 'check',
    description: 'Fail when opcodes grew compared to the baseline',
)]
final class Check extends Stage
{
    public function configure(): void
    {
        parent::configure();
        $this->addOption('base', null, InputOption::VALUE_REQUIRED, 'Base ref for the changed-files mode (default: check.base_ref)');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Check the whole project, not only changed files');
        $this->addOption('update-baseline', null, InputOption::VALUE_NONE, 'Write decreased, new and removed functions to the baseline');
        $this->addOption('suggest', null, InputOption::VALUE_NONE, 'Show which Rector rule would bring the opcodes of grown functions back');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: ' . Format::names(), 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $style = new SymfonyStyle($input, $errorOutput);

        $format = Format::tryFrom((string) $input->getOption('format'));
        if ($format === null) {
            $style->error(\sprintf('Unknown format `%s`: use %s.', (string) $input->getOption('format'), Format::names()));
            return Command::INVALID;
        }

        /** @var Schema\Php $phpConfig */
        $phpConfig = $this->container->get(Schema\Php::class);
        /** @var Schema\Project $projectConfig */
        $projectConfig = $this->container->get(Schema\Project::class);
        /** @var Schema\Check $config */
        $config = $this->container->get(Schema\Check::class);
        $all = (bool) $input->getOption('all');
        try {
            $php = (new PhpBinaryProbe())->probe($phpConfig->binary);
            [$project, $paths] = Targets::resolve([], Path::create((string) \getcwd()), $projectConfig);
            $files = $this->files($project, $paths);
            $baselineFile = $project->root->join(BaselineFile::FILE);
            $baseline = BaselineFile::load($baselineFile);
            $incompatible = $baseline->incompatibility($php->version, OptimizerSettings::hash($php));
            $incompatible === null or throw new BaselineException($incompatible);
            $scope = $all ? null : ChangedFiles::since($project->root, $config->baseRef);
        } catch (PhpBinaryException|\InvalidArgumentException|BaselineException $e) {
            $style->error($e->getMessage());
            $format === Format::Github and $output->write(
                '::error title=opmin check::' . \strtr($e->getMessage(), ['%' => '%25', "\r" => '%0D', "\n" => '%0A']) . "\n",
                false,
                OutputInterface::OUTPUT_RAW,
            );
            return Command::INVALID;
        }

        if ($scope !== null) {
            $changed = \array_flip($scope);
            $files = \array_values(\array_filter($files, static fn(Path $file): bool => isset($changed[$project->relative($file)])));
        }

        $report = $this->countReport($project, $php, $files);
        $comparison = Comparison::compare($baseline, $report, $scope, $config->tolerance, $config->maxOpsNewFunction);
        if ($input->getOption('suggest') && $comparison->failed()) {
            $suggestions = $this->suggester($project, $php, $errorOutput)->suggest($comparison->of(FindingKind::Grown));
            $comparison = $comparison->withSuggestions(static fn(Finding $f) => $suggestions[$f->key] ?? null);
        }

        $base = $scope === null ? null : $config->baseRef;
        $renderer = new Renderer($comparison, $config->tolerance, $config->maxOpsNewFunction, $base, $php->version);
        $format === Format::Table
            ? $this->table($comparison, $config, new SymfonyStyle($input, $output))
            : $output->write($renderer->render($format), false, OutputInterface::OUTPUT_RAW);

        $updated = $input->getOption('update-baseline') && $comparison->updates !== [];
        $updated and $baseline->with($comparison->updates, Info::version())->write($baselineFile);
        $this->summary($style, $comparison, \count($files), $base, $updated);

        if ($report->errors !== []) {
            $style->error(\array_merge(
                [\sprintf('%d file(s) could not be counted:', \count($report->errors))],
                \array_map(static fn(string $file, string $error): string => "{$file}: {$error}", \array_keys($report->errors), $report->errors),
            ));
            return Command::FAILURE;
        }

        return $comparison->failed() ? Command::FAILURE : Command::SUCCESS;
    }

    private function suggester(Project $project, PhpBinary $php, OutputInterface $errorOutput): Suggester
    {
        /** @var Schema\Rector $rectorConfig */
        $rectorConfig = $this->container->get(Schema\Rector::class);
        /** @var Schema\RectorStandard $standard */
        $standard = $this->container->get(Schema\RectorStandard::class);
        $cacheDir = $this->cacheDir();
        $phpTarget = $this->phpTarget($project);

        return new Suggester(
            $project,
            $this->counter($php, $cacheDir),
            new RectorRunner($project, $cacheDir, $phpTarget),
            RuleCatalog::build($rectorConfig, $standard, null, [], $phpTarget),
            $cacheDir->join('tmp'),
            static function (string $message) use ($errorOutput): void {
                $errorOutput->isVerbose() and $errorOutput->writeln("  {$message}");
            },
        );
    }

    private function table(Comparison $comparison, Schema\Check $config, SymfonyStyle $style): void
    {
        $rows = [];
        foreach ($comparison->findings as $finding) {
            $rows[] = [
                $finding->kind->value,
                $finding->key,
                $finding->line === null ? $finding->file : "{$finding->file}:{$finding->line}",
                $finding->before ?? '-',
                $finding->after ?? '-',
                \sprintf('%+d', $finding->delta()),
                $finding->suggestion === null ? '' : "{$finding->suggestion->shortName()} (-{$finding->suggestion->gain})",
            ];
        }

        if ($rows === []) {
            return;
        }

        $headers = ['', 'Function', 'File', 'Before', 'After', 'Delta'];
        $suggestions = \array_filter($comparison->findings, static fn(Finding $f): bool => $f->suggestion !== null) !== [];
        $suggestions ? $headers[] = 'Suggestion' : $rows = \array_map(static fn(array $row): array => \array_slice($row, 0, 6), $rows);
        $style->table($headers, $rows);
        $config->maxOpsNewFunction === null or $style->writeln("Limit for new functions: {$config->maxOpsNewFunction} opcodes.");
    }

    /**
     * @param int<0, max> $files
     * @param non-empty-string|null $base
     */
    private function summary(
        SymfonyStyle $style,
        Comparison $comparison,
        int $files,
        ?string $base,
        bool $updated,
    ): void {
        $scope = $base === null ? 'the project' : "files changed since {$base}";
        if ($files === 0 && $comparison->findings === []) {
            $style->success("No PHP files to check in {$scope}.");
            return;
        }

        $counts = [];
        foreach (FindingKind::cases() as $kind) {
            ($n = $comparison->count($kind)) > 0 and $counts[] = "{$n} " . \str_replace('_', ' ', $kind->value);
        }

        $line = \sprintf(
            '%d function(s) of %d file(s) in %s checked against %s%s.',
            $comparison->checked,
            $files,
            $scope,
            BaselineFile::FILE,
            $counts === [] ? ', no changes' : ': ' . \implode(', ', $counts),
        );

        $updated and $line .= \sprintf(' Updated %d function(s) in %s%s: commit it.', \count($comparison->updates), BaselineFile::FILE, $comparison->failed() ? ' (grown ones keep their counts)' : '');
        if ($comparison->failed()) {
            $style->error($line . ' Opcodes grew: take the change back, run `opmin optimize`, or accept the growth with `opmin baseline`.');
            return;
        }

        if (!$updated && $comparison->count(FindingKind::Decreased) + $comparison->count(FindingKind::New) + $comparison->count(FindingKind::Removed) > 0) {
            $line .= ' Update the baseline: `opmin check --update-baseline` (or `opmin baseline`), then commit it.';
        }

        $style->success($line);
    }
}
