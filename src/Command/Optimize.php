<?php

declare(strict_types=1);

namespace Opmin\Command;

use Internal\Path;
use Opmin\Module\Analysis\ReferenceIndex;
use Opmin\Module\Analysis\Shadow\ShadowIndex;
use Opmin\Module\Common\Cpu;
use Opmin\Module\Config\Schema;
use Opmin\Module\Opcode\CountCache;
use Opmin\Module\Opcode\Dump\OpcacheDumper;
use Opmin\Module\Opcode\OpcodeCounter;
use Opmin\Module\Optimize\Formatter;
use Opmin\Module\Optimize\Optimizer;
use Opmin\Module\Optimize\Rector\RectorRunner;
use Opmin\Module\Optimize\Rector\RuleCatalog;
use Opmin\Module\Optimize\Rector\RuleSpec;
use Opmin\Module\Optimize\RunReport;
use Opmin\Module\Optimize\StepReport;
use Opmin\Module\Optimize\Workspace;
use Opmin\Module\Php\InternalSymbols;
use Opmin\Module\Php\PhpBinaryException;
use Opmin\Module\Php\PhpBinaryProbe;
use Opmin\Module\Project\FileFinder;
use Opmin\Module\Project\Project;
use Opmin\Module\Project\Targets;
use Opmin\Module\Verification\Verifier;
use Opmin\Rector\Rule\AbstractExtractRepeatedReadRector;
use Opmin\Rector\Rule\FullyQualifyGlobalCallsRector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Minimize opcodes: Stage A (Rector) with behavior verification on every step (brief, «Модуль 3»).
 * The LLM stage comes in M4, `--review`, `--resume`, `--guard-perf` in M5, git packages in M7.
 *
 * In a git working tree (must be clean) every accepted step is a commit; outside git the originals
 * are copied to `runs/<ts>/original/`; `--dry-run` restores everything at the end. Every run writes
 * `runs/<ts>/`: the counts before and after, `report.json`, `opmin.patch`, counterexamples.
 *
 * Exit codes: 0 — done (also when nothing could be improved); 1 — the full test run fails after the
 * optimization even with every step taken back, or a step failed; 2 — invalid config or usage, a
 * dirty git tree, an unusable `php.binary`.
 *
 * @internal
 */
#[AsCommand(
    name: 'optimize',
    description: 'Minimize opcodes: Rector and LLM stages with behavior verification',
)]
final class Optimize extends Base
{
    /** Options of later stages: [option, stage]. */
    private const LATER = [
        'review' => 'M5', 'resume' => 'M5', 'guard-perf' => 'M5', 'mutation-check' => 'M5',
        'ref' => 'M7', 'no-docker' => 'M7', 'allow-scripts' => 'M7', 'force-public-api' => 'M7', 'yes' => 'M7',
    ];

    public function configure(): void
    {
        parent::configure();
        $this->addArgument('path', InputArgument::IS_ARRAY, 'Files, directories, globs or a git URL');
        $this->addOption('ref', null, InputOption::VALUE_REQUIRED, 'Git ref (tag, branch) when the path is a git URL');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Change nothing, only write the report and the patch');
        $this->addOption('review', null, InputOption::VALUE_NONE, 'Confirm every change interactively');
        $this->addOption('resume', null, InputOption::VALUE_NONE, 'Continue an interrupted run');
        $this->addOption('guard-perf', null, InputOption::VALUE_NONE, 'Roll back changes that make code slower');
        $this->addOption('with-standard-rector', null, InputOption::VALUE_NONE, 'Enable the standard Rector rules for this run');
        $this->addOption('without-standard-rector', null, InputOption::VALUE_NONE, 'Disable the standard Rector rules for this run');
        $this->addOption('rector-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run only the given Rector rule (FQCN), repeatable');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Formatter: none, or a command with {files} (overrides commands.format)');
        $this->addOption('allow-unverified', null, InputOption::VALUE_NONE, 'Change functions whose behavior is not proven');
        $this->addOption('allow-public-signatures', null, InputOption::VALUE_NONE, 'Allow native type changes of public overridable methods');
        $this->addOption('force-public-api', null, InputOption::VALUE_NONE, 'Touch the public API in package mode');
        $this->addOption('mutation-check', null, InputOption::VALUE_NONE, 'Report the mutation score of the target functions first');
        $this->addOption('no-docker', null, InputOption::VALUE_NONE, 'Do not re-run inside Docker in git package mode');
        $this->addOption('allow-scripts', null, InputOption::VALUE_NONE, 'Run composer scripts and plugins of a git package');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask before running tests of foreign code');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $style = new SymfonyStyle($input, $errorOutput);
        foreach (self::LATER as $option => $stage) {
            if ($input->getOption($option) !== false && $input->getOption($option) !== null) {
                $style->error("--{$option} is not implemented yet (stage {$stage}).");
                return Command::INVALID;
            }
        }

        /** @var list<string> $arguments */
        $arguments = $input->getArgument('path');
        foreach ($arguments as $argument) {
            if (\preg_match('~^(?:git@|[a-z][a-z0-9+.-]*://)~i', $argument) === 1) {
                $style->error('Optimizing a git package by URL is not implemented yet (stage M7).');
                return Command::INVALID;
            }
        }

        if ($input->getOption('with-standard-rector') && $input->getOption('without-standard-rector')) {
            $style->error('--with-standard-rector and --without-standard-rector exclude each other.');
            return Command::INVALID;
        }

        /** @var Schema\Php $phpConfig */
        $phpConfig = $this->container->get(Schema\Php::class);
        /** @var Schema\Project $projectConfig */
        $projectConfig = $this->container->get(Schema\Project::class);
        /** @var Schema\Cache $cacheConfig */
        $cacheConfig = $this->container->get(Schema\Cache::class);
        /** @var Schema\Ignore $ignore */
        $ignore = $this->container->get(Schema\Ignore::class);
        /** @var Schema\Verification $verification */
        $verification = $this->container->get(Schema\Verification::class);
        $input->getOption('allow-unverified') and $verification->allowUnverified = true;
        /** @var Schema\Commands $commands */
        $commands = $this->container->get(Schema\Commands::class);
        /** @var mixed $format */
        $format = $input->getOption('format');
        \is_string($format) && $format !== '' and $commands->format = $format;

        try {
            $php = (new PhpBinaryProbe())->probe($phpConfig->binary);
            [$project, $paths] = Targets::resolve($arguments, Path::create((string) \getcwd()), $projectConfig);
            $files = $this->files($project, $paths, $projectConfig, $ignore);
            $files === [] and throw new \InvalidArgumentException('No PHP files to optimize.');
            $phpTarget = $phpConfig->target ?? $project->phpTarget;
            /** @var Schema\Rector $rectorConfig */
            $rectorConfig = $this->container->get(Schema\Rector::class);
            /** @var Schema\RectorStandard $standard */
            $standard = $this->container->get(Schema\RectorStandard::class);
            /** @var list<string> $only */
            $only = (array) $input->getOption('rector-rule');
            $withStandard = $input->getOption('with-standard-rector') ? true : ($input->getOption('without-standard-rector') ? false : null);
            $rules = RuleCatalog::build($rectorConfig, $standard, $withStandard, $only, $phpTarget);
        } catch (PhpBinaryException|\InvalidArgumentException $e) {
            $style->error($e->getMessage());
            return Command::INVALID;
        }

        $cacheDir = Path::create($cacheConfig->dir);
        $cacheDir->isAbsolute() or $cacheDir = $project->root->join($cacheConfig->dir);
        $runDir = $project->root->join('runs', \date('Ymd-His'));
        $dryRun = (bool) $input->getOption('dry-run');
        try {
            $workspace = Workspace::create($project, $runDir, $dryRun, \array_values(\array_unique(['runs', $project->relative($cacheDir)])));
        } catch (\RuntimeException $e) {
            $style->error($e->getMessage());
            return Command::INVALID;
        }

        $log = static function (string $message) use ($errorOutput): void {
            $errorOutput->isVerbose() and $errorOutput->writeln("  {$message}");
        };
        $style->writeln(\sprintf(
            'Optimizing %d file(s) of %s with %d rule(s), PHP %s (target %s), %s.',
            \count($files),
            (string) $project->root,
            \count($rules),
            $php->version,
            $phpTarget ?? 'unknown',
            $workspace->mode(),
        ));

        $rules = $this->withRuntimeOptions($rules, $project, $cacheDir, $runDir, $php);
        $counter = new OpcodeCounter(new OpcacheDumper($php, Cpu::count()), new CountCache($cacheDir, $php));
        /** @var Schema\Tests $tests */
        $tests = $this->container->get(Schema\Tests::class);
        $verifier = new Verifier($project, $php, $verification, $commands, $tests, $cacheDir, $phpTarget, $log);
        /** @var Schema\Readability $readability */
        $readability = $this->container->get(Schema\Readability::class);
        /** @var Schema\Signatures $signatures */
        $signatures = $this->container->get(Schema\Signatures::class);
        $optimizer = new Optimizer(
            $project,
            $php,
            $workspace,
            $counter,
            ReferenceIndex::build($project, $cacheDir),
            new RectorRunner($project, $cacheDir, $phpTarget),
            Formatter::create($project, $php, $commands->format),
            $verifier,
            $rectorConfig,
            $readability,
            $signatures,
            $ignore,
            $cacheDir->join('tmp'),
            (bool) $input->getOption('allow-public-signatures'),
            static function (string $message) use ($errorOutput): void {
                $errorOutput->writeln("  {$message}", OutputInterface::VERBOSITY_NORMAL);
            },
        );

        $report = $optimizer->run($files, $rules);
        $patch = $workspace->finish();
        $report->patch = $patch === null ? null : $project->relative($patch);
        \file_put_contents(
            (string) $runDir->join('report.json'),
            \json_encode($report->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n",
        );
        $this->summary(new SymfonyStyle($input, $output), $report, $project, $runDir, $dryRun);

        $failed = $report->finalTests === false || \array_filter($report->steps, static fn(StepReport $s): bool => $s->error !== null) !== [];

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * The target files without excluded, ignored, vendor and generated ones.
     *
     * @param list<Path> $paths
     * @return list<Path>
     */
    private function files(Project $project, array $paths, Schema\Project $config, Schema\Ignore $ignore): array
    {
        $exclude = \array_values(\array_unique([...$config->exclude, ...$ignore->paths, 'vendor']));
        $files = (new FileFinder())->find($project, $paths, $exclude);

        return \array_values(\array_filter($files, static function (Path $file) use ($project, $ignore): bool {
            $relative = $project->relative($file);
            foreach ($ignore->paths as $path) {
                $path = \trim(\str_replace('\\', '/', $path), '/');
                if ($relative === $path || \str_starts_with($relative, $path . '/')) {
                    return false;
                }
            }

            return !\str_starts_with($relative, 'vendor/') && !Targets::generated($file);
        }));
    }

    /**
     * Options opmin's own rules get from the run: the shadow index and the internal symbols of
     * `php.binary` for the FQN rule, `min_reads` for the extract rules.
     *
     * @param list<RuleSpec> $rules
     * @return list<RuleSpec>
     */
    private function withRuntimeOptions(array $rules, Project $project, Path $cacheDir, Path $runDir, \Opmin\Module\Php\PhpBinary $php): array
    {
        $result = [];
        foreach ($rules as $rule) {
            if (\is_a($rule->class, FullyQualifyGlobalCallsRector::class, true)) {
                $shadows = $runDir->join('shadows.json');
                $symbols = $runDir->join('symbols.json');
                if (!$shadows->exists()) {
                    \file_put_contents((string) $shadows, \json_encode(ShadowIndex::build($project, $cacheDir)->toArray(), \JSON_THROW_ON_ERROR));
                    \file_put_contents((string) $symbols, \json_encode(InternalSymbols::of($php)->toArray(), \JSON_THROW_ON_ERROR));
                }

                $rule = $rule->withOptions(($rule->options ?? []) + [
                    FullyQualifyGlobalCallsRector::SHADOWS => (string) $shadows,
                    FullyQualifyGlobalCallsRector::SYMBOLS => (string) $symbols,
                ]);
            } elseif (\is_a($rule->class, AbstractExtractRepeatedReadRector::class, true)) {
                $rule = $rule->withOptions(($rule->options ?? []) + [AbstractExtractRepeatedReadRector::MIN_READS => 'auto']);
            }

            $result[] = $rule;
        }

        return $result;
    }

    private function summary(SymfonyStyle $style, RunReport $report, Project $project, Path $runDir, bool $dryRun): void
    {
        $rows = [];
        foreach ($report->steps as $step) {
            $rule = \substr($step->rule, (int) \strrpos('\\' . $step->rule, '\\'));
            $rows[] = [
                $step->pass,
                $rule,
                \count($step->accepted),
                \count($step->rejected),
                $step->gain() === 0 ? '' : '-' . $step->gain(),
                $step->error ?? ($step->commit === null ? '' : \substr($step->commit, 0, 8)),
            ];
        }

        $rows === [] or $style->table(['Pass', 'Rule', 'Kept', 'Rolled back', 'Opcodes', 'Commit / error'], $rows);
        $rejected = [];
        foreach ($report->steps as $step) {
            foreach ($step->rejected as $r) {
                $rejected[] = [$r['file'], $r['function'], $r['reason']];
            }
        }

        $rejected === [] || !$style->isVerbose() or $style->table(['File', 'Function', 'Why rolled back'], \array_slice($rejected, 0, 50));
        $report->notes === [] or $style->note($report->notes);
        $report->finalTests === true and $style->writeln('The full run of the project\'s tests passes on the result.');
        $report->finalTests === false and $style->error('The full test run fails after the optimization: see report.json.');
        $style->success(\sprintf(
            '%d → %d opcodes (%s). Report: %s%s',
            $report->opsBefore,
            $report->opsAfter,
            $report->opsAfter === $report->opsBefore ? 'no change' : \sprintf('-%d', $report->opsBefore - $report->opsAfter),
            $project->relative($runDir->join('report.json')),
            $report->patch === null ? '' : ', patch: ' . $report->patch . ($dryRun ? ' (dry run: files restored)' : ''),
        ));
    }
}
