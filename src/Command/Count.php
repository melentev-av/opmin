<?php

declare(strict_types=1);

namespace Opmin\Command;

use Internal\Path;
use Opmin\Info;
use Opmin\Module\Analysis\ReferenceIndex;
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
use Opmin\Module\Project\Targets;
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
 * `--sort`, `--top` and `--min` shape the table only: the JSON is the full report `diff` reads, and a
 * cut one would show the functions left out as removed and sum wrong totals.
 *
 * Exit codes: 0 — counted; 1 — some files could not be counted (they are listed, the rest is
 * reported); 2 — invalid config or options, a missing path or an unusable `php.binary`.
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
        $this->addArgument('path', InputArgument::IS_ARRAY, 'Files, directories or globs to analyze (default: `paths` from the config)');
        $this->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Only functions matching the FQN pattern, e.g. App\\Service\\*');
        $this->addOption('exclude', null, InputOption::VALUE_REQUIRED, 'Comma-separated paths to skip (overrides `exclude`)');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table | json', 'table');
        $this->addOption('sort', null, InputOption::VALUE_REQUIRED, 'Table order: file | ops_opt | ops_raw (numbers descending; default: file, ops_opt with --top)');
        $this->addOption('top', null, InputOption::VALUE_REQUIRED, 'Table: only the first N functions, main code skipped');
        $this->addOption('min', null, InputOption::VALUE_REQUIRED, 'Table: only functions with at least N ops_opt, main code skipped');
    }

    #[\Override]
    protected function requiresConfig(InputInterface $input): bool
    {
        return true;
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

        try {
            $view = $this->view($input, $format);
        } catch (\InvalidArgumentException $e) {
            $style->error($e->getMessage());
            return Command::INVALID;
        }

        /** @var Schema\Php $phpConfig */
        $phpConfig = $this->container->get(Schema\Php::class);
        /** @var Schema\Project $projectConfig */
        $projectConfig = $this->container->get(Schema\Project::class);

        try {
            $php = (new PhpBinaryProbe())->probe($phpConfig->binary);
            /** @var list<string> $arguments */
            $arguments = $input->getArgument('path');
            [$project, $paths] = Targets::resolve($arguments, Path::create((string) \getcwd()), $projectConfig);
            $files = (new FileFinder())->find($project, $paths, $projectConfig->exclude);
        } catch (PhpBinaryException|\InvalidArgumentException $e) {
            $style->error($e->getMessage());
            return Command::INVALID;
        }

        $cacheDir = $this->cacheDir();
        $counter = new OpcodeCounter(new OpcacheDumper($php, Cpu::count()), new CountCache($this->cacheStore(), $php));

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

        # Flags a function gets from the rest of the project: called by name, inspected by reflection.
        $functions = ReferenceIndex::build($project, $cacheDir, $this->cacheStore())->apply($this->filter($result->functions, $input->getOption('filter')));
        $report = CountReport::create(
            opmin: Info::version(),
            php: $php->version,
            phpTarget: $phpConfig->target ?? $project->phpTarget,
            optimizerHash: OptimizerSettings::hash($php),
            functions: $functions,
            errors: $result->errors,
        );

        $format === 'json'
            ? $output->write($report->toJson(), false, OutputInterface::OUTPUT_RAW)
            : $this->table($report, new SymfonyStyle($input, $output), ...$view);

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
     * @param int<0, max> $least
     * @return int<0, max>|null
     * @throws \InvalidArgumentException
     */
    private static function integer(mixed $value, string $option, int $least): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;
        if (\preg_match('/^\d+$/', $value) !== 1 || (int) $value < $least) {
            throw new \InvalidArgumentException(\sprintf(
                '%s must be an integer of at least %d, `%s` given.',
                $option,
                $least,
                $value,
            ));
        }

        return \max(0, (int) $value);
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

    /**
     * Validated `--sort`, `--top` and `--min`.
     *
     * @return array{'file'|'ops_opt'|'ops_raw', positive-int|null, int<0, max>|null}
     * @throws \InvalidArgumentException
     */
    private function view(InputInterface $input, string $format): array
    {
        /** @var string|null $sort A value-required option. */
        $sort = $input->getOption('sort');
        /** @var positive-int|null $top */
        $top = self::integer($input->getOption('top'), '--top', 1);
        $min = self::integer($input->getOption('min'), '--min', 0);

        if ($format === 'json' && ($sort !== null || $top !== null || $min !== null)) {
            throw new \InvalidArgumentException(
                '--sort, --top and --min shape the table only: --format=json is the full report for `opmin diff`. '
                . "Cut it with jq, e.g. jq '.functions | to_entries | sort_by(-.value.ops_opt) | .[:20]'.",
            );
        }

        $sort ??= $top !== null ? 'ops_opt' : 'file';
        if (!\in_array($sort, ['file', 'ops_opt', 'ops_raw'], true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown sort `%s`: use file, ops_opt or ops_raw.', $sort));
        }

        return [$sort, $top, $min];
    }

    /**
     * @param 'file'|'ops_opt'|'ops_raw' $sort
     * @param positive-int|null $top
     * @param int<0, max>|null $min
     */
    private function table(CountReport $report, SymfonyStyle $style, string $sort, ?int $top, ?int $min): void
    {
        $functions = [];
        foreach ($report->functions as $key => $function) {
            # Main code is counted but never optimized, so it is no answer to "what to optimize".
            if (($top !== null || $min !== null) && !$function->optimizable) {
                continue;
            }
            if ($min !== null && $function->opsOpt < $min) {
                continue;
            }
            $functions[] = [$key, $function];
        }
        \usort($functions, /**
             * @param array{non-empty-string, FunctionCount} $a
             * @param array{non-empty-string, FunctionCount} $b
             */ static fn(array $a, array $b): int => match ($sort) {
            'file' => [$a[1]->file, $a[1]->line, $a[0]] <=> [$b[1]->file, $b[1]->line, $b[0]],
            'ops_opt' => [$b[1]->opsOpt, $a[0]] <=> [$a[1]->opsOpt, $b[0]],
            'ops_raw' => [$b[1]->opsRaw, $a[0]] <=> [$a[1]->opsRaw, $b[0]],
        });
        $selected = $top !== null || $min !== null;
        $top !== null and $functions = \array_slice($functions, 0, $top);
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

        # Per-file sums of a selection would read as the files' totals.
        if (!$selected && \count($files) > 1) {
            \ksort($files, \SORT_STRING);
            $style->table(['File', 'Functions', 'ops_raw', 'ops_opt'], \array_values($files));
        }

        $selected and $style->writeln(\sprintf('Shown: %d of %d functions, main code skipped.', \count($rows), \count($report->functions)));
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
