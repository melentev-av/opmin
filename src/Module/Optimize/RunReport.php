<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize;

use Opmin\Module\Report\Environment;
use Opmin\Module\Report\MarkdownReport;
use Opmin\Module\Report\RejectionKind;

/**
 * Result of an optimization run (brief, «Отчёт»): opcodes before and after, every step, every kept
 * function with its proof, every rolled-back change with its reason, the environment of the run.
 *
 * {@see self::toArray()} is `report.json`. Its shape is versioned by `schema` and changes only in a
 * major release of opmin: fields may be added, never renamed or removed.
 *
 * @internal
 */
final class RunReport
{
    public const SCHEMA = 1;

    /** @var list<StepReport> */
    public array $steps = [];

    /** @var list<string> */
    public array $notes = [];

    /** @var list<string> Things that make this run not comparable with an earlier one. */
    public array $warnings = [];

    /** @var array<non-empty-string, array{file: non-empty-string, ops_after: int, flags: list<string>}> Changed top-level functions after the run. */
    public array $functions = [];

    public int $opsAfter = 0;

    /** Result of the final full run of the project's tests; null when there is no runner. */
    public ?bool $finalTests = null;

    public ?string $patch = null;
    public ?Environment $environment = null;

    /** The run stopped before its end (Ctrl+C, `q` in the review): `--resume` continues it. */
    public bool $interrupted = false;

    /**
     * @param 'optimize'|'llm' $kind
     */
    public function __construct(
        public int $opsBefore,
        public readonly string $runDir,
        public readonly string $kind = 'optimize',
    ) {}

    /**
     * The weaker of two proofs: `unverified` < `tests` < `diff-tested`.
     */
    public static function weaker(?string $a, string $b): string
    {
        $rank = ['unverified' => 0, 'tests' => 1, 'diff-tested' => 2];

        return $a === null || ($rank[$b] ?? 0) < ($rank[$a] ?? 0) ? $b : $a;
    }

    /**
     * Writes `report.json` and `report.md` to the run directory.
     *
     * @param array<string, mixed> $extra More fields of `report.json` (the attempts of the LLM stage).
     * @return array<string, mixed> The data of `report.json`.
     */
    public function write(array $extra = []): array
    {
        $data = $this->toArray() + $extra;
        \file_put_contents(
            $this->runDir . '/report.json',
            \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n",
        );
        $title = \sprintf('opmin %s: %s', $this->kind === 'llm' ? 'LLM stage' : 'optimize', \basename($this->runDir));
        \file_put_contents($this->runDir . '/report.md', MarkdownReport::render($data, $title));

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $functions = $this->functionsArray();
        $rejected = $this->rejectedArray();
        $saved = $this->opsBefore - $this->opsAfter;

        return [
            'schema' => self::SCHEMA,
            'kind' => $this->kind,
            'environment' => $this->environment?->toArray(),
            'warnings' => $this->warnings,
            'totals' => [
                'ops_before' => $this->opsBefore,
                'ops_after' => $this->opsAfter,
                'saved' => $saved,
                'percent' => $this->opsBefore === 0 ? 0.0 : \round($saved * 100 / $this->opsBefore, 2),
                'functions_changed' => \count($functions),
                'changes_rolled_back' => \count($rejected),
            ],
            'final_tests' => $this->finalTests,
            'interrupted' => $this->interrupted,
            'patch' => $this->patch,
            'functions' => $functions,
            'rejected' => $rejected,
            'counterexamples' => $this->counterexamples(),
            'notes' => $this->notes,
            'steps' => \array_map(static fn(StepReport $s): array => $s->toArray(), $this->steps),
        ];
    }

    /**
     * Every kept top-level function: before → after, the steps that saved opcodes, the weakest proof
     * of its changes, the lowest coverage of its differential tests, the project's tests that run it.
     *
     * @return array<non-empty-string, array<string, mixed>>
     */
    private function functionsArray(): array
    {
        $result = [];
        foreach ($this->steps as $step) {
            foreach ($step->accepted as $change) {
                $top = $change['function'];
                $entry = $result[$top] ?? [
                    'file' => $change['file'],
                    'ops_before' => 0,
                    'ops_after' => $this->functions[$top]['ops_after'] ?? null,
                    'saved' => 0,
                    'status' => null,
                    'diff_coverage' => null,
                    'inputs' => null,
                    'tests' => [],
                    'flags' => $this->functions[$top]['flags'] ?? [],
                    'executed_gain' => false,
                    'time_change_percent' => null,
                    'gains' => [],
                ];
                $entry['saved'] += $change['gain'];
                $entry['executed_gain'] = $entry['executed_gain'] || $step->executedGain;
                $entry['status'] = self::weaker($entry['status'], $change['status']);
                foreach ($change['checks'] ?? [] as $check) {
                    $coverage = \is_float($check['coverage'] ?? null) || \is_int($check['coverage'] ?? null) ? (float) $check['coverage'] : null;
                    $coverage === null or $entry['diff_coverage'] = $entry['diff_coverage'] === null ? $coverage : \min($entry['diff_coverage'], $coverage);
                    /** @var mixed $time */
                    $time = $check['time_change_percent'] ?? null;
                    \is_float($time) || \is_int($time) and $entry['time_change_percent'] = \max($entry['time_change_percent'] ?? -100.0, (float) $time);
                    /** @var mixed $inputs */
                    $inputs = $check['inputs'] ?? null;
                    \is_int($inputs) and $entry['inputs'] = \max($entry['inputs'] ?? 0, $inputs);
                    /** @var list<string> $tests */
                    $tests = \is_array($check['tests'] ?? null) ? $check['tests'] : [];
                    $entry['tests'] = \array_values(\array_unique([...$entry['tests'], ...$tests]));
                }

                $entry['gains'][] = [
                    'rule' => $step->rule,
                    'source' => $step->rule === LlmCandidate::class ? 'llm' : 'rector',
                    'pass' => $step->pass,
                    'gain' => $change['gain'],
                    'executed_gain' => $step->executedGain,
                    'commit' => $step->commit,
                ];
                $result[$top] = $entry;
            }
        }

        foreach ($result as $top => $entry) {
            # Steps measure their gains exactly: the sum leads back to the count before the run.
            \is_int($entry['ops_after']) and $result[$top]['ops_before'] = $entry['ops_after'] + $entry['saved'];
        }

        \ksort($result, \SORT_STRING);

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rejectedArray(): array
    {
        $result = [];
        foreach ($this->steps as $step) {
            foreach ($step->rejected as $rejected) {
                $result[] = [
                    'file' => $rejected['file'],
                    'function' => $rejected['function'],
                    'rule' => $step->rule,
                    'pass' => $step->pass,
                    'kind' => RejectionKind::fromReason($rejected['reason'])->value,
                    'reason' => $rejected['reason'],
                ] + (isset($rejected['counterexample']) ? ['counterexample' => $rejected['counterexample']] : []);
            }
        }

        return $result;
    }

    /**
     * Counterexamples saved as tests and inputs, relative to the run directory.
     *
     * @return list<string>
     */
    private function counterexamples(): array
    {
        $dir = $this->runDir . '/counterexamples';
        if (!\is_dir($dir)) {
            return [];
        }

        $files = \glob($dir . '/*');
        $files === false and $files = [];
        \sort($files, \SORT_STRING);

        return \array_map(static fn(string $f): string => 'counterexamples/' . \basename($f), $files);
    }
}
