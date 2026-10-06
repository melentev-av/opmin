<?php

declare(strict_types=1);

namespace Opmin\Command;

use Internal\Path;
use Opmin\Info;
use Opmin\Module\Common\Cpu;
use Opmin\Module\Config\Schema;
use Opmin\Module\Opcode\CountCache;
use Opmin\Module\Opcode\Dump\OpcacheDumper;
use Opmin\Module\Opcode\FunctionCount;
use Opmin\Module\Opcode\OpcodeCounter;
use Opmin\Module\Opcode\OptimizerSettings;
use Opmin\Module\Opcode\Report\CountReport;
use Opmin\Module\Php\PhpBinaryException;
use Opmin\Module\Php\PhpBinaryProbe;
use Opmin\Module\Project\FileFinder;
use Opmin\Module\Project\Project;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Count opcodes per function, method and closure.
 *
 * Exit codes: 0 — counted; 1 — some files could not be counted (they are listed, the rest is
 * reported); 2 — invalid config, a missing path or an unusable `php.binary`.
 *
 * @internal
 */
#[AsCommand(
    name: 'count',
    description: 'Count opcodes per function, method and closure',
)]
final class Count extends Base
{
    public function configure(): void
    {
        parent::configure();
        $this->addArgument('path', InputArgument::IS_ARRAY, 'Files or directories to analyze (default: `paths` from the config)');
        $this->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Only functions matching the FQN pattern, e.g. App\\Service\\*');
        $this->addOption('exclude', null, InputOption::VALUE_REQUIRED, 'Comma-separated paths to skip (overrides `exclude`)');
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

        /** @var Schema\Php $phpConfig */
        $phpConfig = $this->container->get(Schema\Php::class);
        /** @var Schema\Project $projectConfig */
        $projectConfig = $this->container->get(Schema\Project::class);
        /** @var Schema\Cache $cacheConfig */
        $cacheConfig = $this->container->get(Schema\Cache::class);

        try {
            $php = (new PhpBinaryProbe())->probe($phpConfig->binary);
            [$project, $paths] = $this->target($input, $projectConfig);
            $files = (new FileFinder())->find($project, $paths, $projectConfig->exclude);
        } catch (PhpBinaryException|\InvalidArgumentException $e) {
            $style->error($e->getMessage());
            return Command::INVALID;
        }

        $cacheDir = Path::create($cacheConfig->dir);
        $cacheDir->isAbsolute() or $cacheDir = $project->root->join($cacheConfig->dir);
        $counter = new OpcodeCounter(new OpcacheDumper($php, Cpu::count()), new CountCache($cacheDir, $php));

        $progress = null;
        if ($errorOutput->isDecorated() && !$output->isQuiet() && \count($files) > 1) {
            $progress = new ProgressBar($errorOutput, \count($files));
            $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %message%');
            $progress->setMessage('');
            $progress->start();
        }

        $result = $counter->count($project, $files, static function (int $done, int $total, string $file) use ($progress, $errorOutput): void {
            $progress?->setMessage($file);
            $progress?->setProgress($done);
            $errorOutput->isVerbose() and $progress === null and $errorOutput->writeln("  {$done}/{$total} {$file}");
        });
        $progress?->finish();
        $progress?->clear();

        $report = CountReport::create(
            opmin: Info::version(),
            php: $php->version,
            phpTarget: $phpConfig->target ?? $project->phpTarget,
            optimizerHash: OptimizerSettings::hash($php),
            functions: $this->filter($result->functions, $input->getOption('filter')),
            errors: $result->errors,
        );

        $format === 'json'
            ? $output->write($report->toJson(), false, OutputInterface::OUTPUT_RAW)
            : $this->table($report, new SymfonyStyle($input, $output));

        $errorOutput->isVerbose() and $errorOutput->writeln(\sprintf(
            '  %d file(s): %d from the cache, %d compiled by PHP %s (%s)',
            \count($files),
            $result->cached,
            \count($files) - $result->cached,
            $php->version,
            $php->path,
        ));

        if ($result->errors !== []) {
            $this->reportErrors($style, $result->errors, $php->version, $report->phpTarget);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * The project and the paths to analyze: the arguments (relative to the current directory) or the
     * config `paths` (relative to the project root).
     *
     * @return array{Project, list<Path>}
     */
    private function target(InputInterface $input, Schema\Project $config): array
    {
        $cwd = Path::create((string) \getcwd());
        /** @var list<string> $arguments */
        $arguments = $input->getArgument('path');
        $paths = \array_map(static fn(string $p): Path => Path::create($p)->absolute((string) $cwd), $arguments);
        foreach ($paths as $path) {
            $path->exists() or throw new \InvalidArgumentException("Path `{$path}` does not exist.");
        }

        $project = Project::detect($paths[0] ?? $cwd, $cwd);
        if ($paths === []) {
            $configured = $config->paths;
            # The default `src` falls back to `app` (Laravel and similar layouts).
            $configured === (new Schema\Project())->paths && !$project->root->join('src')->exists()
                && $project->root->join('app')->isDir() and $configured = ['app'];
            $paths = \array_map(static fn(string $p): Path => $project->root->join($p), $configured);
            foreach ($paths as $path) {
                $path->exists() or throw new \InvalidArgumentException(
                    "Path `{$path}` from the config key `paths` does not exist; pass paths as arguments or fix `paths`.",
                );
            }
        }

        return [$project, $paths];
    }

    /**
     * @param list<FunctionCount> $functions
     * @return list<FunctionCount>
     */
    private function filter(array $functions, mixed $pattern): array
    {
        if (!\is_string($pattern) || $pattern === '') {
            return $functions;
        }

        # `--filter='App\\Service\\*'` in single quotes keeps both backslashes.
        $pattern = \str_replace('\\\\', '\\', $pattern);
        $regex = '~^' . \str_replace('\*', '.*', \preg_quote($pattern, '~')) . '$~i';

        return \array_values(\array_filter($functions, static fn(FunctionCount $f): bool => \preg_match($regex, $f->key) === 1));
    }

    private function table(CountReport $report, SymfonyStyle $style): void
    {
        $functions = [];
        foreach ($report->functions as $key => $function) {
            $functions[] = [$key, $function];
        }
        \usort($functions, /**
             * @param array{non-empty-string, FunctionCount} $a
             * @param array{non-empty-string, FunctionCount} $b
             */ static fn(array $a, array $b): int => [$a[1]->file, $a[1]->line, $a[0]] <=> [$b[1]->file, $b[1]->line, $b[0]]);
        $rows = [];
        $files = [];
        foreach ($functions as [$key, $function]) {
            $rows[] = [$key, "{$function->file}:{$function->line}", $function->opsRaw, $function->opsOpt, $function->vars, $function->tmps];
            $files[$function->file] ??= [$function->file, 0, 0, 0];
            ++$files[$function->file][1];
            $files[$function->file][2] += $function->opsRaw;
            $files[$function->file][3] += $function->opsOpt;
        }

        $style->table(['Function', 'File:line', 'ops_raw', 'ops_opt', 'vars', 'tmps'], $rows);

        if (\count($files) > 1) {
            \ksort($files, \SORT_STRING);
            $style->table(['File', 'Functions', 'ops_raw', 'ops_opt'], \array_values($files));
        }

        $style->writeln(\sprintf(
            'Total: <info>%d</info> opcodes after the optimizer (%d before) in %d functions of %d files, PHP %s, optimizer %s.',
            $report->opsOpt(),
            $report->opsRaw(),
            \count($report->functions),
            $report->files,
            $report->php,
            $report->optimizerHash,
        ));
    }

    /**
     * @param array<non-empty-string, non-empty-string> $errors
     */
    private function reportErrors(SymfonyStyle $style, array $errors, string $runtime, ?string $target): void
    {
        $lines = [\sprintf('%d file(s) could not be counted:', \count($errors))];
        foreach ($errors as $file => $message) {
            $lines[] = "{$file}: {$message}";
        }

        $runtimeMinor = \implode('.', \array_slice(\explode('.', $runtime), 0, 2));
        if ($target !== null && \version_compare($target, $runtimeMinor, '>')
            && \preg_grep('/ParseError|syntax error/', $errors) !== []
        ) {
            $lines[] = "The code targets PHP {$target}, but php.binary is PHP {$runtime}: syntax newer than "
                . 'php.binary cannot be compiled. Set php.binary to the PHP your production runs.';
        }

        $style->warning($lines);
    }
}
