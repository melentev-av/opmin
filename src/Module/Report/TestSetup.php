<?php

declare(strict_types=1);

namespace Opmin\Module\Report;

/**
 * What of the project's tests a run could use to prove a change (brief, «Верификация»): a test runner,
 * and the coverage map that tells which tests run a function. A `tests` proof needs both.
 *
 * Read from what the run wrote: `environment.test_runner` and the notes of the verifier.
 *
 * @internal
 */
final readonly class TestSetup
{
    /** The note of the verifier when no runner is found (`Verifier::levelsOneAndTwo()`). */
    public const NOTE_NO_RUNNER = 'No test runner found (tests.runner)';

    /** The note of the verifier when the runner gives no coverage map (`Verifier::baseline()`). */
    public const NOTE_NO_COVERAGE_MAP = 'No coverage map of the project\'s tests';

    public function __construct(
        public bool $runner,
        public bool $coverageMap,
    ) {}

    /**
     * @param array<array-key, mixed>|null $environment `environment` of `report.json`; null when unknown.
     * @param list<string> $notes
     */
    public static function of(?array $environment, array $notes): self
    {
        $noted = static fn(string $start): bool => \array_filter($notes, static fn(string $n): bool => \str_starts_with($n, $start)) !== [];
        $runner = !$noted(self::NOTE_NO_RUNNER) && ($environment === null || \is_string($environment['test_runner'] ?? null));

        return new self($runner, $runner && !$noted(self::NOTE_NO_COVERAGE_MAP));
    }
}
