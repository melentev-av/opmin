<?php

declare(strict_types=1);

namespace Opmin\Command;

use Internal\Path;
use Opmin\Module\Analysis\Shadow\ShadowIndex;
use Opmin\Module\Config\Schema;
use Opmin\Module\Config\Schema\NativeTypesPolicy;
use Opmin\Module\Optimize\Rector\RuleCatalog;
use Opmin\Module\Optimize\Rector\RuleSpec;
use Opmin\Module\Optimize\Review\ConsoleReviewer;
use Opmin\Module\Optimize\Optimizer;
use Opmin\Module\Optimize\RunReport;
use Opmin\Module\Optimize\RunState;
use Opmin\Module\Optimize\StepReport;
use Opmin\Module\Optimize\Workspace;
use Opmin\Module\Package\PackageException;
use Opmin\Module\Package\PackageRun;
use Opmin\Module\Php\InternalSymbols;
use Opmin\Module\Php\PhpBinaryException;
use Opmin\Module\Php\PhpBinaryProbe;
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
 * Stage B is the Claude Code skill driving `llm:targets` → `apply-candidate` → `llm:finish`; `--review`,
 * `--resume`, `--guard-perf` come in M5.
 *
 * A git URL (`opmin optimize <git-url> --ref=<tag>`) optimizes a clone of the package in a temporary
 * workspace, in Docker unless `--no-docker`: see {@see PackageRun}. The report and the patch land in the
 * current directory.
 *
 * In a git working tree every accepted step is a commit (`--with-git`: its target files must be clean); outside
 * git the originals are copied to `runs/<ts>/original/`; `--dry-run` restores everything at the end.
 * Every run writes `runs/<ts>/`: the counts before and after, `report.json`, `opmin.patch`, counterexamples.
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
final class Optimize extends Stage
{
    /** Options of later stages: [option, stage]. */
    private const LATER = [
        'mutation-check' => 'after the MVP',
    ];

    /** Options that only make sense for a git package. */
    private const PACKAGE_ONLY = ['ref', 'no-docker', 'allow-scripts', 'force-public-api', 'yes'];

    /** Options passed on to the opmin that optimizes the clone of a git package. */
    private const PACKAGE_FORWARDED = ['dry-run', 'guard-perf', 'with-standard-rector', 'without-standard-rector', 'allow-unverified'];

    public function configure(): void
    {
        parent::configure();
        $this->addArgument('path', InputArgument::IS_ARRAY, 'Files, directories, globs or a git URL');
        $this->addOption('ref', null, InputOption::VALUE_REQUIRED, 'Git ref (tag, branch) when the path is a git URL');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Change nothing, only write the report and the patch');
        $this->addOption('review', null, InputOption::VALUE_NONE, 'Confirm every change interactively');
        $this->addOption('resume', null, InputOption::VALUE_OPTIONAL, 'Continue an interrupted run: the latest one or the given run directory');
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
        $this->addWithGitOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $style = new SymfonyStyle($input, $errorOutput);
        foreach (self::LATER as $option => $stage) {
            if ($input->getOption($option) !== false && $input->getOption($option) !== null) {
                $style->error("--{$option} is not implemented yet ({$stage}).");
                return Command::INVALID;
            }
        }

        /** @var list<string> $arguments */
        $arguments = $input->getArgument('path');
        $urls = \array_values(\array_filter($arguments, static fn(string $a): bool => \preg_match('~^(?:git@|[a-z][a-z0-9+.-]*://)~i', $a) === 1));
        if ($urls !== []) {
            return $this->package($input, $output, $style, $arguments);
        }

        foreach (self::PACKAGE_ONLY as $option) {
            if ($input->getOption($option)) {
                $style->error("--{$option} is for a git package: opmin optimize <git-url> --ref=<tag>.");
                return Command::INVALID;
            }
        }

        if ($input->getOption('with-standard-rector') && $input->getOption('without-standard-rector')) {
            $style->error('--with-standard-rector and --without-standard-rector exclude each other.');
            return Command::INVALID;
        }

        $resume = $input->hasParameterOption('--resume');
        if ($resume && $arguments !== []) {
            $style->error('--resume continues the files of the interrupted run: do not pass paths.');
            return Command::INVALID;
        }

        /** @var Schema\Php $phpConfig */
        $phpConfig = $this->container->get(Schema\Php::class);
        /** @var Schema\Project $projectConfig */
        $projectConfig = $this->container->get(Schema\Project::class);
        /** @var Schema\Verification $verification */
        $verification = $this->container->get(Schema\Verification::class);
        /** @var Schema\Commands $commands */
        $commands = $this->container->get(Schema\Commands::class);

        try {
            $php = (new PhpBinaryProbe())->probe($phpConfig->binary);
            [$project, $paths] = Targets::resolve($arguments, Path::create((string) \getcwd()), $projectConfig);
            $state = $resume ? $this->resumed($project, $input) : null;
            /** @var array{dry_run?: bool, allow_unverified?: bool, allow_public_signatures?: bool, guard_perf?: bool, format?: string|null} $options */
            $options = $state?->options ?? [
                'dry_run' => (bool) $input->getOption('dry-run'),
                'allow_unverified' => (bool) $input->getOption('allow-unverified'),
                'allow_public_signatures' => (bool) $input->getOption('allow-public-signatures'),
                'guard_perf' => (bool) $input->getOption('guard-perf'),
                'format' => \is_string($input->getOption('format')) && $input->getOption('format') !== '' ? $input->getOption('format') : null,
            ];
            ($options['allow_unverified'] ?? false) and $verification->allowUnverified = true;
            if ($options['guard_perf'] ?? false) {
                /** @var Schema\GuardPerf $guardPerf */
                $guardPerf = $this->container->get(Schema\GuardPerf::class);
                $guardPerf->enabled = true;
            }

            $format = $options['format'] ?? null;
            \is_string($format) && $format !== '' and $commands->format = $format;
            $phpTarget = $this->phpTarget($project);
            if ($state !== null) {
                $files = \array_map(static fn(string $f): Path => $project->root->join($f), $state->files);
                $rules = $state->rules;
            } else {
                $files = $this->files($project, $paths);
                $files === [] and throw new \InvalidArgumentException('No PHP files to optimize.');
                /** @var Schema\Rector $rectorConfig */
                $rectorConfig = $this->container->get(Schema\Rector::class);
                /** @var Schema\RectorStandard $standard */
                $standard = $this->container->get(Schema\RectorStandard::class);
                /** @var list<string> $only */
                $only = (array) $input->getOption('rector-rule');
                $withStandard = $input->getOption('with-standard-rector') ? true : ($input->getOption('without-standard-rector') ? false : null);
                $rules = RuleCatalog::build($rectorConfig, $standard, $withStandard, $only, $phpTarget);
            }
        } catch (PhpBinaryException|\InvalidArgumentException|\RuntimeException $e) {
            $style->error($e->getMessage());
            return Command::INVALID;
        }

        $cacheDir = $this->cacheDir();
        $runDir = $state?->runDir ?? $this->newRunDir($project);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        try {
            if ($state !== null) {
                $rewritten = $state->restore($project->root);
                $rewritten === [] or $style->note('Restored to the last accepted step: ' . \implode(', ', \array_slice($rewritten, 0, 10)));
                $dryRun || $state->head === null or Workspace::rewind($project, $state->head);
            }

            $workspace = $this->workspace($project, $runDir, $dryRun, $files);
        } catch (\RuntimeException $e) {
            $style->error($e->getMessage());
            return Command::INVALID;
        }

        $style->writeln(\sprintf(
            '%s %d file(s) of %s with %d rule(s), PHP %s (target %s), %s.',
            $state === null ? 'Optimizing' : 'Resuming ' . $project->relative($runDir) . ':',
            \count($files),
            (string) $project->root,
            \count($rules),
            $php->version,
            $phpTarget ?? 'unknown',
            $workspace->mode(),
        ));

        if ($state === null) {
            $rules = $this->withRuntimeOptions($rules, $project, $cacheDir, $runDir, $php);
            $state = RunState::start($runDir, \array_map($project->relative(...), $files), $rules, $options);
        }

        $optimizer = $this->optimizer($project, $php, $workspace, $output, (bool) ($options['allow_public_signatures'] ?? false));
        [$environment, $warnings] = $this->environment($project, $php, $optimizer, $runDir);
        $warnings === [] or $style->warning($warnings);
        if ($input->getOption('review')) {
            # Without a terminal (-n, CI, an agent) every change that passes the checks is applied.
            $input->isInteractive()
                ? $optimizer->withReviewer(new ConsoleReviewer(new SymfonyStyle($input, $errorOutput)))
                : $style->warning('--review needs an interactive session: every change that passes the checks is applied.');
        }

        # Red tests prove nothing about a change: every change would be rolled back, so stop before any.
        $red = $optimizer->failingTestsOnOriginal();
        if ($red !== null) {
            $style->error([
                'The project\'s tests fail on the original code: fix them first, or set tests.runner to none to verify with the differential tests only.',
                Verifier::whyRed($red),
            ]);
            return Command::FAILURE;
        }

        $signalled = $this->onSignals($optimizer, $errorOutput);
        $report = $optimizer->run($files, $rules, $state);
        $report->environment = $environment;
        $report->warnings = $warnings;
        $patch = $workspace->finish();
        $report->patch = $patch === null ? null : $project->relative($patch);
        $report->write();
        $this->summary(new SymfonyStyle($input, $output), $report, $project, $runDir, $dryRun);
        if ($signalled()) {
            return 130;
        }

        $failed = $report->finalTests === false || \array_filter($report->steps, static fn(StepReport $s): bool => $s->error !== null) !== [];

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * `opmin optimize <git-url> [paths in the package] --ref=<tag>`: see {@see PackageRun}.
     *
     * @param list<string> $arguments The URL first, then paths inside the package.
     */
    private function package(InputInterface $input, OutputInterface $output, SymfonyStyle $style, array $arguments): int
    {
        $url = $arguments[0];
        if ($url === '' || \preg_match('~^(?:git@|[a-z][a-z0-9+.-]*://)~i', $url) !== 1) {
            $style->error('Pass the git URL first, then paths inside the package.');
            return Command::INVALID;
        }

        $paths = \array_slice($arguments, 1);
        foreach ($paths as $path) {
            if (\preg_match('~^(?:git@|[a-z][a-z0-9+.-]*://)~i', $path) === 1 || \str_starts_with($path, '/') || \str_contains($path, '..')) {
                $style->error("Pass one git URL first, then paths inside the package: `{$path}` is not one.");
                return Command::INVALID;
            }
        }

        foreach (['--resume', '--config'] as $name) {
            if ($input->hasParameterOption($name)) {
                $style->error("{$name} does not work for a git package: the package's own opmin.yaml applies, override keys with --set.");
                return Command::INVALID;
            }
        }

        /** @var Schema\Signatures $signatures */
        $signatures = $this->container->get(Schema\Signatures::class);
        $force = (bool) $input->getOption('force-public-api');
        $docker = !$input->getOption('no-docker') && PackageRun::dockerAvailable();
        $docker || $input->getOption('no-docker') or $style->note('Docker is not available: the package would run without isolation.');

        $inner = [];
        foreach (self::PACKAGE_FORWARDED as $option) {
            $input->getOption($option) and $inner[] = "--{$option}";
        }

        /** @var list<string> $rules */
        $rules = (array) $input->getOption('rector-rule');
        foreach ($rules as $rule) {
            $rule === '' or $inner[] = "--rector-rule={$rule}";
        }

        /** @var string|null $format */
        $format = $input->getOption('format');
        $format === null || $format === '' or $inner[] = "--format={$format}";
        /** @var list<string> $sets */
        $sets = (array) $input->getOption('set');
        foreach ($sets as $set) {
            $inner[] = "--set={$set}";
        }

        # The public API of a package is called from code nobody can see (brief, «Изменение сигнатур»).
        if (!$force) {
            $inner[] = '--set=signatures.public_api=false';
            $signatures->nativeTypes === NativeTypesPolicy::All and $inner[] = '--set=signatures.native_types=non_overridable';
        } elseif ($input->getOption('allow-public-signatures')) {
            $inner[] = '--allow-public-signatures';
        }

        $interactive = $input->isInteractive() && !$docker;
        $input->getOption('review') && $interactive and $inner[] = '--review';
        $interactive or $inner[] = '--no-interaction';
        $inner[] = $output->isDecorated() ? '--ansi' : '--no-ansi';
        $output->isVerbose() and $inner[] = '-' . \str_repeat('v', match (true) {
            $output->isDebug() => 3,
            $output->isVeryVerbose() => 2,
            default => 1,
        });

        /** @var Schema\Package $config */
        $config = $this->container->get(Schema\Package::class);
        /** @var Schema\Php $php */
        $php = $this->container->get(Schema\Php::class);
        /** @var string|null $ref */
        $ref = $input->getOption('ref');
        $run = new PackageRun($url, $ref === '' ? null : $ref, $inner, $paths, $docker, (bool) $input->getOption('allow-scripts'), $config, $php, $style, Path::create((string) \getcwd()));
        $yes = (bool) $input->getOption('yes');

        try {
            return $run->run(static fn(): bool => $yes || $input->isInteractive() && $style->confirm('Run the package\'s composer install and tests on this machine?', false));
        } catch (PackageException $e) {
            $style->error($e->getMessage());
            return Command::INVALID;
        }
    }

    /**
     * The run to continue: `--resume=<run directory>` or the latest one that did not finish.
     *
     * @throws \RuntimeException
     */
    private function resumed(Project $project, InputInterface $input): RunState
    {
        /** @var mixed $value */
        $value = $input->getOption('resume');
        if (\is_string($value) && $value !== '') {
            $dir = Path::create($value);
            $dir->isAbsolute() or $dir = $project->root->join($value);
        } else {
            $dir = RunState::latest($project->root->join('runs'))
                ?? throw new \RuntimeException('No interrupted run in runs/: nothing to resume.');
        }

        $state = RunState::load($dir);
        $state->phase === 'finished' and throw new \RuntimeException("The run {$project->relative($dir)} is finished: nothing to resume.");

        return $state;
    }

    /**
     * Ctrl+C or SIGTERM stops the run: the step under way is dropped (its tools got the signal too),
     * the state stays at the last accepted step for `--resume`. A second signal aborts at once.
     *
     * @return \Closure(): bool Whether a signal came.
     */
    private function onSignals(Optimizer $optimizer, OutputInterface $output): \Closure
    {
        $count = 0;
        if (\function_exists('pcntl_async_signals') && \function_exists('pcntl_signal')) {
            \pcntl_async_signals(true);
            $handler = static function () use ($optimizer, $output, &$count): void {
                if (++$count > 1) {
                    $output->writeln('Aborted: `opmin optimize --resume` restores the files and continues.');
                    /** @psalm-suppress ForbiddenCode The user asked twice: no step can be finished cleanly. */
                    exit(130);
                }

                $optimizer->interrupt();
                $output->writeln('<comment>Stopping: the current step is dropped, `opmin optimize --resume` continues. Again to abort at once.</comment>');
            };
            \pcntl_signal(\SIGINT, $handler);
            \pcntl_signal(\SIGTERM, $handler);
        }

        return static function () use (&$count): bool {
            return $count > 0;
        };
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
            $project->relative($runDir->join('report.md')),
            $report->patch === null ? '' : ', patch: ' . $report->patch . ($dryRun ? ' (dry run: files restored)' : ''),
        ));
    }
}
