<?php

declare(strict_types=1);

namespace Opmin\Module\Tests;

/**
 * Result of a run of the project's tests, read from the runner's machine-readable report.
 *
 * @internal
 */
final readonly class TestResult
{
    /**
     * @param list<non-empty-string> $failed Ids of failed and errored tests.
     * @param non-negative-int $tests Tests run.
     * @param string $output Raw output of the runner, for the report.
     */
    public function __construct(
        public bool $success,
        public array $failed = [],
        public int $tests = 0,
        public float $seconds = 0.0,
        public string $output = '',
    ) {}
}
