<?php

declare(strict_types=1);

namespace Opmin\Module\Tests;

use Opmin\Module\Project\Project;
use Symfony\Component\Process\Process;

/**
 * `tests.runner: command`: the configured command, success by its exit code; no selection, no
 * coverage map.
 *
 * @internal
 */
final class CommandAdapter implements TestRunnerAdapter
{
    /**
     * @param non-empty-string $command
     * @param positive-int $timeoutSeconds
     */
    public function __construct(
        private readonly Project $project,
        private readonly string $command,
        private readonly int $timeoutSeconds = 3600,
    ) {}

    public static function detect(Project $project): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'command';
    }

    public function runAll(): TestResult
    {
        $process = Process::fromShellCommandline($this->command, (string) $this->project->root, null, null, $this->timeoutSeconds);
        $start = \hrtime(true);
        $process->run();

        return new TestResult(
            $process->getExitCode() === 0,
            seconds: (float) (\hrtime(true) - $start) / 1e9,
            output: $process->getOutput() . $process->getErrorOutput(),
        );
    }

    public function runFiltered(array $testIds): TestResult
    {
        return $testIds === [] ? new TestResult(true) : $this->runAll();
    }

    public function collectCoverageMap(): ?CoverageMap
    {
        return null;
    }

    public function counterexampleTest(string $name, string $description, array $setup, string $call, ?string $expected, ?array $exception, ?string $output, bool $strict = false): string
    {
        $code = "<?php\n\n" . ($strict ? "declare(strict_types=1);\n\n" : '') . "// " . \str_replace("\n", "\n// ", $description) . "\n// Plain script: the project's test runner is a command opmin does not know.\n\n";
        foreach ($setup as $line) {
            $code .= $line . "\n";
        }

        $code .= "\$result = {$call};\n";
        $expected === null or $code .= "\$result === {$expected} or throw new \\RuntimeException('Behavior changed: ' . var_export(\$result, true));\n";

        return $code;
    }
}
