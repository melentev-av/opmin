<?php

declare(strict_types=1);

namespace Opmin\Module\Tests;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Project\Project;
use Symfony\Component\Process\Process;

/**
 * PHPUnit: `--log-junit` for the result, `--filter` with an escaped regex for a selection,
 * `--coverage-xml` (Xdebug or pcov in `php.binary`, `<source>` in `phpunit.xml`) for the map of
 * function lines to tests.
 *
 * @internal
 */
class PhpUnitAdapter implements TestRunnerAdapter
{
    /**
     * @param non-empty-string|null $binary `tests.command`: overrides the runner binary.
     * @param positive-int $timeoutSeconds
     */
    public function __construct(
        protected readonly Project $project,
        protected readonly PhpBinary $php,
        protected readonly Path $workDir,
        protected readonly ?string $binary = null,
        protected readonly int $timeoutSeconds = 3600,
    ) {}

    public static function detect(Project $project): bool
    {
        return $project->root->join('vendor/bin/phpunit')->isFile()
            || $project->root->join('phpunit.xml')->isFile()
            || $project->root->join('phpunit.xml.dist')->isFile()
            || self::requires($project, 'phpunit/phpunit');
    }

    /**
     * `--filter` regex that runs exactly the given tests. A test id names a data set the way coverage
     * reports do (`T::m#0`, `T::m#name`); PHPUnit filters on `T::m with data set #0` / `"name"`. An id
     * without a data set runs all of them.
     *
     * @param non-empty-list<non-empty-string> $testIds
     */
    public static function filter(array $testIds): string
    {
        return '/^(?:' . \implode('|', \array_values(\array_unique(self::alternatives($testIds)))) . ')$/';
    }

    /**
     * Whether `php.binary` has a coverage driver (Xdebug, pcov).
     */
    public static function hasCoverageDriver(PhpBinary $php): bool
    {
        $process = new Process([$php->path, '-r', 'echo extension_loaded("pcov") || extension_loaded("xdebug") ? 1 : 0;']);
        $process->run();

        return \trim($process->getOutput()) === '1';
    }

    public function name(): string
    {
        return 'phpunit';
    }

    public function runAll(): TestResult
    {
        return $this->runJunit([]);
    }

    public function runFiltered(array $testIds): TestResult
    {
        if ($testIds === []) {
            return new TestResult(true);
        }

        return $this->runJunit(['--filter', static::filter($testIds)]);
    }

    public function collectCoverageMap(): ?CoverageMap
    {
        if (!self::hasCoverageDriver($this->php)) {
            return null;
        }

        $dir = $this->workDir->join('coverage-' . \bin2hex(\random_bytes(4)));
        try {
            $this->runJunit(['--coverage-xml', (string) $dir], coverage: true);
            $map = CoverageXmlReport::read((string) $dir);

            return $map === null || $map->isEmpty() ? null : $map;
        } finally {
            FS::remove($dir);
        }
    }

    public function counterexampleTest(string $name, string $description, array $setup, string $call, ?string $expected, ?array $exception, ?string $output, bool $strict = false): string
    {
        $body = self::indent($setup, 8);
        if ($exception !== null) {
            $body .= "        \$this->expectException(\\{$exception['class']}::class);\n"
                . '        $this->expectExceptionMessage(' . \var_export($exception['message'], true) . ");\n";
        }

        $output === null or $body .= '        $this->expectOutputString(' . \var_export($output, true) . ");\n";
        $body .= "\n        \$result = {$call};\n";
        $expected === null or $body .= "\n        self::assertSame({$expected}, \$result);\n";
        $expected === null && $exception === null && $output === null
            and $body .= "\n        self::markTestIncomplete('The original result is not a plain value, see the report.');\n";

        return "<?php\n\n" . self::strictTypes($strict) . "use PHPUnit\\Framework\\TestCase;\n\n"
            . "/**\n * " . \str_replace("\n", "\n * ", $description) . "\n */\n"
            . "final class {$name} extends TestCase\n{\n    public function testBehaviorIsKept(): void\n    {\n{$body}    }\n}\n";
    }

    /**
     * Regex alternatives of {@see self::filter()}, one per test id.
     *
     * @param list<non-empty-string> $testIds
     * @return list<string>
     */
    protected static function alternatives(array $testIds): array
    {
        $alternatives = [];
        foreach ($testIds as $id) {
            if (\preg_match('/^(.+?)(?:#(.+)| with data set (.+))$/s', $id, $m) === 1) {
                $set = $m[2] !== '' ? $m[2] : $m[3];
                $set = \preg_match('/^#?\d+$/', $set) === 1 ? '#' . \ltrim($set, '#') : '"' . \trim($set, '"') . '"';
                $alternatives[] = \preg_quote($m[1] . ' with data set ' . $set, '/');
                continue;
            }

            $alternatives[] = \preg_quote($id, '/') . '(?: with data set .*)?';
        }

        return $alternatives;
    }

    protected static function requires(Project $project, string $package): bool
    {
        $json = @\file_get_contents((string) $project->root->join('composer.json'));
        /** @var mixed $composer */
        $composer = $json === false ? null : \json_decode($json, true);
        if (!\is_array($composer)) {
            return false;
        }

        foreach (['require', 'require-dev'] as $section) {
            /** @var mixed $packages */
            $packages = $composer[$section] ?? null;
            if (\is_array($packages) && \array_key_exists($package, $packages)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Weak typing unless the counterexample was called from a strict file: it decides coercion.
     */
    protected static function strictTypes(bool $strict): string
    {
        return $strict ? "declare(strict_types=1);\n\n" : '';
    }

    /**
     * @param list<string> $lines
     */
    protected static function indent(array $lines, int $spaces): string
    {
        $pad = \str_repeat(' ', $spaces);

        return \implode('', \array_map(static fn(string $line): string => $pad . $line . "\n", $lines));
    }

    /**
     * @return list<string>
     */
    protected function command(): array
    {
        $binary = $this->binary ?? 'vendor/bin/phpunit';

        return CommandLine::withPhp(CommandLine::split($binary), $this->php, $this->project->root);
    }

    /**
     * @param list<string> $args
     */
    protected function runJunit(array $args, bool $coverage = false): TestResult
    {
        FS::mkdir((string) $this->workDir);
        $junit = $this->workDir->join('junit-' . \bin2hex(\random_bytes(4)) . '.xml');
        try {
            $process = $this->process([...$this->command(), '--log-junit', (string) $junit, '--colors=never', ...$args], $coverage);
            $process->run();
            $report = JunitReport::read((string) $junit);
            $output = $process->getOutput() . $process->getErrorOutput();
            if ($report === null) {
                return new TestResult(false, output: $output);
            }

            return new TestResult($process->getExitCode() === 0, $report['failed'], $report['tests'], $report['seconds'], $output);
        } finally {
            FS::remove($junit);
        }
    }

    /**
     * @param list<string> $command
     */
    protected function process(array $command, bool $coverage): Process
    {
        $coverage and $command = [...\array_slice($command, 0, 1), '-d', 'pcov.enabled=1', ...\array_slice($command, 1)];
        $env = $coverage ? ['XDEBUG_MODE' => 'coverage'] : ['XDEBUG_MODE' => 'off'];

        return new Process($command, (string) $this->project->root, $env, null, $this->timeoutSeconds);
    }
}
