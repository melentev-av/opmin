<?php

declare(strict_types=1);

namespace Opmin\Command;

use Internal\Path;
use Opmin\Module\Analysis\ReferenceIndex;
use Opmin\Module\Common\Cpu;
use Opmin\Module\Config\Schema;
use Opmin\Module\Opcode\CountCache;
use Opmin\Module\Opcode\Dump\OpcacheDumper;
use Opmin\Info;
use Opmin\Module\Opcode\OpcodeCounter;
use Opmin\Module\Opcode\OptimizerSettings;
use Opmin\Module\Opcode\Report\CountReport;
use Opmin\Module\Optimize\Formatter;
use Opmin\Module\Optimize\Optimizer;
use Opmin\Module\Optimize\Review\Declined;
use Opmin\Module\Config\Exception\ConfigException;
use Opmin\Module\Optimize\Rector\RectorRunner;
use Opmin\Module\Optimize\Workspace;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Project\FileFinder;
use Opmin\Module\Project\Project;
use Opmin\Module\Project\Targets;
use Opmin\Module\Report\Environment;
use Opmin\Module\Verification\Verifier;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What the commands that change or guard code share (`optimize`, the LLM stage, `baseline`, `check`):
 * the target files, the cache, the counter and the optimizer with its checks.
 *
 * @internal
 */
abstract class Stage extends Base
{
    /**
     * The target files without excluded, ignored, vendor and generated ones.
     *
     * @param list<Path> $paths
     * @return list<Path>
     */
    protected function files(Project $project, array $paths): array
    {
        /** @var Schema\Project $config */
        $config = $this->container->get(Schema\Project::class);
        /** @var Schema\Ignore $ignore */
        $ignore = $this->container->get(Schema\Ignore::class);
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
     * `runs/<timestamp>`, with a suffix when a run of the same second exists.
     */
    protected function newRunDir(Project $project): Path
    {
        $base = \date('Ymd-His');
        $dir = $project->root->join('runs', $base);
        for ($i = 2; $dir->exists(); ++$i) {
            $dir = $project->root->join('runs', "{$base}-{$i}");
        }

        return $dir;
    }

    /**
     * @return non-empty-string|null
     */
    protected function phpTarget(Project $project): ?string
    {
        /** @var Schema\Php $phpConfig */
        $phpConfig = $this->container->get(Schema\Php::class);

        return $phpConfig->target ?? $project->phpTarget;
    }

    /**
     * Relative paths whose changes do not make the git tree dirty: the runs, the cache.
     *
     * @return list<non-empty-string>
     */
    protected function ignoredPaths(Project $project): array
    {
        # The review writes opmin.baseline.yaml and commits it at the end of the run.
        return \array_values(\array_unique(['runs', $project->relative($this->cacheDir()), Declined::FILE]));
    }

    /**
     * The environment of a run and what changed in it since the previous run with a report.
     *
     * @return array{Environment, list<non-empty-string>}
     */
    protected function environment(Project $project, PhpBinary $php, Optimizer $optimizer, Path $runDir): array
    {
        $tools = $optimizer->tools();
        $environment = Environment::detect($project, $php, $this->phpTarget($project), $tools['test_runner'], $tools['formatter']);
        $previous = Environment::previous($runDir->parent(), $runDir);

        return [$environment, $previous === null ? [] : $environment->changesSince($previous[1], $previous[0])];
    }

    protected function counter(PhpBinary $php, Path $cacheDir): OpcodeCounter
    {
        return new OpcodeCounter(new OpcacheDumper($php, Cpu::count()), new CountCache($cacheDir, $php));
    }

    /**
     * Opcode counts of the given files as a count report (keys deduplicated like in `count`).
     *
     * @param list<Path> $files
     */
    protected function countReport(Project $project, PhpBinary $php, array $files): CountReport
    {
        $result = $files === [] ? null : $this->counter($php, $this->cacheDir())->count($project, $files);

        return CountReport::create(
            opmin: Info::version(),
            php: $php->version,
            phpTarget: $this->phpTarget($project),
            optimizerHash: OptimizerSettings::hash($php),
            functions: $result?->functions ?? [],
            errors: $result?->errors ?? [],
        );
    }

    protected function optimizer(
        Project $project,
        PhpBinary $php,
        Workspace $workspace,
        OutputInterface $output,
        bool $allowPublicSignatures,
    ): Optimizer {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $cacheDir = $this->cacheDir();
        $phpTarget = $this->phpTarget($project);
        /** @var Schema\Verification $verification */
        $verification = $this->container->get(Schema\Verification::class);
        /** @var Schema\Commands $commands */
        $commands = $this->container->get(Schema\Commands::class);
        /** @var Schema\Tests $tests */
        $tests = $this->container->get(Schema\Tests::class);
        $verbose = static function (string $message) use ($errorOutput): void {
            $errorOutput->isVerbose() and $errorOutput->writeln("  {$message}");
        };
        /** @var Schema\Rector $rectorConfig */
        $rectorConfig = $this->container->get(Schema\Rector::class);
        /** @var Schema\Readability $readability */
        $readability = $this->container->get(Schema\Readability::class);
        /** @var Schema\Signatures $signatures */
        $signatures = $this->container->get(Schema\Signatures::class);
        /** @var Schema\Ignore $ignore */
        $ignore = $this->container->get(Schema\Ignore::class);
        /** @var Schema\GuardPerf $guardPerf */
        $guardPerf = $this->container->get(Schema\GuardPerf::class);

        try {
            $declined = Declined::load($project->root);
        } catch (\InvalidArgumentException $e) {
            throw new ConfigException($e->getMessage(), previous: $e);
        }

        return new Optimizer(
            $project,
            $php,
            $workspace,
            $this->counter($php, $cacheDir),
            ReferenceIndex::build($project, $cacheDir),
            new RectorRunner($project, $cacheDir, $phpTarget),
            Formatter::create($project, $php, $commands->format),
            new Verifier($project, $php, $verification, $commands, $tests, $cacheDir, $phpTarget, $verbose, $guardPerf),
            $rectorConfig,
            $readability,
            $signatures,
            $ignore,
            $cacheDir->join('tmp'),
            $allowPublicSignatures,
            static function (string $message) use ($errorOutput): void {
                $errorOutput->writeln("  {$message}", OutputInterface::VERBOSITY_NORMAL);
            },
            $declined,
        );
    }
}
