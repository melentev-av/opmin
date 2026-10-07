<?php

declare(strict_types=1);

namespace Opmin\Command;

use Internal\Path;
use Opmin\Module\Analysis\ReferenceIndex;
use Opmin\Module\Common\Cpu;
use Opmin\Module\Config\Schema;
use Opmin\Module\Opcode\CountCache;
use Opmin\Module\Opcode\Dump\OpcacheDumper;
use Opmin\Module\Opcode\OpcodeCounter;
use Opmin\Module\Optimize\Formatter;
use Opmin\Module\Optimize\Optimizer;
use Opmin\Module\Optimize\Rector\RectorRunner;
use Opmin\Module\Optimize\Workspace;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Project\FileFinder;
use Opmin\Module\Project\Project;
use Opmin\Module\Project\Targets;
use Opmin\Module\Verification\Verifier;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What the commands that change code share (`optimize`, the LLM stage): the target files, the cache,
 * the counter and the optimizer with its checks.
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

    protected function cacheDir(Project $project): Path
    {
        /** @var Schema\Cache $cacheConfig */
        $cacheConfig = $this->container->get(Schema\Cache::class);
        $cacheDir = Path::create($cacheConfig->dir);

        return $cacheDir->isAbsolute() ? $cacheDir : $project->root->join($cacheConfig->dir);
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
        return \array_values(\array_unique(['runs', $project->relative($this->cacheDir($project))]));
    }

    protected function counter(PhpBinary $php, Path $cacheDir): OpcodeCounter
    {
        return new OpcodeCounter(new OpcacheDumper($php, Cpu::count()), new CountCache($cacheDir, $php));
    }

    protected function optimizer(
        Project $project,
        PhpBinary $php,
        Workspace $workspace,
        OutputInterface $output,
        bool $allowPublicSignatures,
    ): Optimizer {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $cacheDir = $this->cacheDir($project);
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

        return new Optimizer(
            $project,
            $php,
            $workspace,
            $this->counter($php, $cacheDir),
            ReferenceIndex::build($project, $cacheDir),
            new RectorRunner($project, $cacheDir, $phpTarget),
            Formatter::create($project, $php, $commands->format),
            new Verifier($project, $php, $verification, $commands, $tests, $cacheDir, $phpTarget, $verbose),
            $rectorConfig,
            $readability,
            $signatures,
            $ignore,
            $cacheDir->join('tmp'),
            $allowPublicSignatures,
            static function (string $message) use ($errorOutput): void {
                $errorOutput->writeln("  {$message}", OutputInterface::VERBOSITY_NORMAL);
            },
        );
    }
}
