<?php

declare(strict_types=1);

namespace Opmin\Module\Tests;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Project\Project;
use Symfony\Component\Process\Process;

/**
 * Testo: the `--json` report for the result (gate on `status: passed`), repeated `--filter=<id>` for
 * a selection, `--coverage-xml` (PHPUnit XML format, needs Xdebug or pcov) for the coverage map.
 *
 * @internal
 */
final class TestoAdapter implements TestRunnerAdapter
{
    /**
     * @param non-empty-string|null $binary `tests.command`: overrides the runner binary.
     * @param positive-int $timeoutSeconds
     */
    public function __construct(
        private readonly Project $project,
        private readonly PhpBinary $php,
        private readonly Path $workDir,
        private readonly ?string $binary = null,
        private readonly int $timeoutSeconds = 3600,
    ) {}

    public static function detect(Project $project): bool
    {
        if ($project->root->join('vendor/bin/testo')->isFile() || $project->root->join('testo.php')->isFile()) {
            return true;
        }

        $json = @\file_get_contents((string) $project->root->join('composer.json'));

        return $json !== false && \str_contains($json, '"testo/testo"');
    }

    public function name(): string
    {
        return 'testo';
    }

    public function runAll(): TestResult
    {
        return $this->run([]);
    }

    public function runFiltered(array $testIds): TestResult
    {
        if ($testIds === []) {
            return new TestResult(true);
        }

        $args = \array_map(static fn(string $id): string => '--filter=' . $id, $testIds);

        # Thousands of selected tests do not fit into a command line: then all of them run.
        return $this->run(\strlen(\implode(' ', $args)) <= PhpUnitAdapter::MAX_FILTER ? $args : []);
    }

    public function collectCoverageMap(): ?CoverageMap
    {
        if (!PhpUnitAdapter::hasCoverageDriver($this->php)) {
            return null;
        }

        $dir = $this->workDir->join('coverage-' . \bin2hex(\random_bytes(4)));
        try {
            $this->run(['--coverage-xml=' . (string) $dir], coverage: true);
            $map = CoverageXmlReport::read((string) $dir);

            return $map === null || $map->isEmpty() ? null : $map;
        } finally {
            FS::remove($dir);
        }
    }

    public function counterexampleTest(string $name, string $description, array $setup, string $call, ?string $expected, ?array $exception, ?string $output, bool $strict = false): string
    {
        $body = '';
        foreach ($setup as $line) {
            $body .= "        {$line}\n";
        }

        $returnType = $exception === null ? 'void' : 'never';
        $exception === null or $body .= "        Expect::exception(\\{$exception['class']}::class)->withMessage(" . \var_export($exception['message'], true) . ");\n";
        $body .= "\n        \$result = {$call};\n";
        # Testo: actual first, expected second.
        $expected === null or $body .= "\n        Assert::same(\$result, {$expected});\n";
        $output === null or $body .= "        // The original version prints: " . \var_export($output, true) . "\n";
        $expected === null && $exception === null && $output === null and $body .= "        // The original result is not a plain value, see the report.\n";

        return "<?php\n\n" . self::strictTypes($strict) . "use Testo\\Assert;\nuse Testo\\Expect;\nuse Testo\\Test;\n\n"
            . "/**\n * " . \str_replace("\n", "\n * ", $description) . "\n */\n"
            . "#[Test]\nfinal class {$name}\n{\n    public function behaviorIsKept(): {$returnType}\n    {\n{$body}    }\n}\n";
    }

    /**
     * Weak typing unless the counterexample was called from a strict file: it decides coercion.
     */
    private static function strictTypes(bool $strict): string
    {
        return $strict ? "declare(strict_types=1);\n\n" : '';
    }

    /**
     * The JSON report: the last JSON object of stdout (it may be pretty-printed; anything printed
     * before it is not the report).
     */
    private static function lastJson(string $output): string
    {
        $output = \trim($output);
        $offset = \strlen($output);
        while ($offset > 0 && ($start = \strrpos($output, '{', $offset - \strlen($output) - 1)) !== false) {
            $candidate = \substr($output, $start);
            if (($start === 0 || $output[$start - 1] === "\n") && \is_array(\json_decode($candidate, true))) {
                return $candidate;
            }

            $offset = $start;
        }

        return '';
    }

    /**
     * @param list<string> $args
     */
    private function run(array $args, bool $coverage = false): TestResult
    {
        $command = CommandLine::withPhp(CommandLine::split($this->binary ?? 'vendor/bin/testo'), $this->php, $this->project->root);
        $coverage and $command = [...\array_slice($command, 0, 1), '-d', 'pcov.enabled=1', ...\array_slice($command, 1)];
        FS::mkdir((string) $this->workDir);
        $process = new Process([...$command, '--json', '--no-ansi', ...($coverage ? [] : ['--no-coverage']), ...$args], (string) $this->project->root, [
            'XDEBUG_MODE' => $coverage ? 'coverage' : 'off',
        ], null, $this->timeoutSeconds);
        $process->run();
        $output = $process->getOutput();
        /** @var mixed $report */
        $report = \json_decode(self::lastJson($output), true);
        if (!\is_array($report)) {
            return new TestResult(false, output: $output . $process->getErrorOutput());
        }

        $failed = [];
        /** @var list<array<string, mixed>> $failures */
        $failures = \is_array($report['failures'] ?? null) ? $report['failures'] : [];
        foreach ($failures as $failure) {
            /** @var mixed $test */
            $test = $failure['test'] ?? null;
            \is_string($test) && $test !== '' and $failed[] = $test;
        }

        /** @var array<string, int> $totals */
        $totals = \is_array($report['totals'] ?? null) ? $report['totals'] : [];

        return new TestResult(
            ($report['status'] ?? null) === 'passed' && $process->getExitCode() === 0,
            $failed,
            \max(0, (int) ($totals['total'] ?? 0)),
            (float) ($report['duration'] ?? 0.0),
            $output . $process->getErrorOutput(),
        );
    }
}
