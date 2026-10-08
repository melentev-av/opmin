<?php

declare(strict_types=1);

namespace Opmin\Module\Tests;

use Opmin\Module\Project\Project;

/**
 * The project's own test runner (brief, «Адаптеры тест-раннеров»): run everything, run a selection,
 * find which tests execute a function. Results come from machine-readable reports, never from the
 * text the runner prints.
 *
 * @internal
 */
interface TestRunnerAdapter
{
    /**
     * Whether the project uses this runner.
     */
    public static function detect(Project $project): bool;

    /**
     * Name for messages and reports: `phpunit`, `pest`, `testo`, `command`.
     *
     * @return non-empty-string
     */
    public function name(): string;

    public function runAll(): TestResult;

    /**
     * @param list<non-empty-string> $testIds Ids from {@see self::collectCoverageMap()}; an empty list runs nothing.
     */
    public function runFiltered(array $testIds): TestResult;

    /**
     * Function lines → tests; null when the runner or the PHP cannot collect coverage (no Xdebug or
     * pcov in `php.binary`, the `command` runner): every check then runs all tests.
     */
    public function collectCoverageMap(): ?CoverageMap;

    /**
     * A test in the runner's format that reproduces a counterexample of the differential tester.
     *
     * @param non-empty-string $name Base name of the test (a class name).
     * @param list<string> $setup PHP statements that build the input.
     * @param non-empty-string $call PHP expression of the call.
     * @param string|null $expected PHP literal of what the original returns; null when it is not a plain value.
     * @param array{class: string, message: string}|null $exception The exception the original throws.
     * @param string|null $output What the original prints.
     * @param bool $strict The input was called from a `strict_types=1` file.
     */
    public function counterexampleTest(string $name, string $description, array $setup, string $call, ?string $expected, ?array $exception, ?string $output, bool $strict = false): string;
}
