<?php

declare(strict_types=1);

namespace Opmin\Module\Tests;

use Internal\Path;
use Opmin\Module\Config\Schema\TestRunner;
use Opmin\Module\Config\Schema\Tests;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Project\Project;

/**
 * Picks the test runner of the project: `tests.runner`, or for `auto` the first one detected by
 * `composer.json` and config files — Pest (runs on PHPUnit, so checked first), PHPUnit, Testo — and
 * `tests.command` when none is.
 *
 * @internal
 */
final class TestRunnerFactory
{
    /**
     * @return TestRunnerAdapter|null Null when the project has no tests opmin can run.
     * @throws \InvalidArgumentException `runner: command` without `tests.command`.
     */
    public static function create(Project $project, Tests $config, PhpBinary $php, Path $workDir): ?TestRunnerAdapter
    {
        $command = $config->command;

        return match ($config->runner) {
            TestRunner::PhpUnit => new PhpUnitAdapter($project, $php, $workDir, $command),
            TestRunner::Pest => new PestAdapter($project, $php, $workDir, $command),
            TestRunner::Testo => new TestoAdapter($project, $php, $workDir, $command),
            TestRunner::Command => new CommandAdapter(
                $project,
                $command ?? throw new \InvalidArgumentException('tests.runner is `command`, but tests.command is not set.'),
            ),
            TestRunner::Auto => match (true) {
                PestAdapter::detect($project) => new PestAdapter($project, $php, $workDir, $command),
                PhpUnitAdapter::detect($project) => new PhpUnitAdapter($project, $php, $workDir, $command),
                TestoAdapter::detect($project) => new TestoAdapter($project, $php, $workDir, $command),
                $command !== null => new CommandAdapter($project, $command),
                default => null,
            },
        };
    }
}
