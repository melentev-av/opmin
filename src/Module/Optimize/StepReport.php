<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize;

use Opmin\Module\Verification\FunctionResult;

/**
 * What one rule did in one pass: the functions it changed and kept, the ones rolled back and why.
 *
 * @internal
 */
final class StepReport
{
    /** @var list<array{file: non-empty-string, function: non-empty-string, gain: int, status: string, checks?: list<array<string, mixed>>}> */
    public array $accepted = [];

    /** @var list<array{file: non-empty-string, function: string, reason: string, counterexample?: array<string, mixed>}> */
    public array $rejected = [];

    /** @var array<non-empty-string, string> Files the step wrote => their content before it. */
    public array $before = [];

    public ?string $commit = null;
    public ?string $error = null;

    /** The rule saves executed opcodes, not static ones (brief, «Важное замечание по метрике»). */
    public bool $executedGain = false;

    /**
     * @param non-empty-string $rule FQCN.
     */
    public function __construct(
        public readonly string $rule,
        public readonly int $pass,
    ) {}

    /**
     * What the report keeps of the verification of one function: how it is proven, the branch
     * coverage of the differential tests, the inputs, the project's tests that execute it.
     *
     * @return array{key: non-empty-string, status: string, coverage: float|null, inputs: int|null, tests: list<string>}
     */
    public static function check(FunctionResult $result): array
    {
        return [
            'key' => $result->key,
            'status' => $result->status,
            'coverage' => $result->verdict === null ? null : \round($result->verdict->coverage, 1),
            'inputs' => $result->verdict?->inputs,
            'tests' => $result->tests,
        ];
    }

    public function gain(): int
    {
        return \array_sum(\array_column($this->accepted, 'gain'));
    }

    /**
     * @param non-empty-string $file
     * @param array<string, mixed>|null $counterexample The shrunk input on which the versions differ.
     */
    public function reject(string $file, string $function, string $reason, ?array $counterexample = null): void
    {
        $this->rejected[] = ['file' => $file, 'function' => $function, 'reason' => $reason]
            + ($counterexample === null ? [] : ['counterexample' => $counterexample]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'pass' => $this->pass,
            'gain' => $this->gain(),
            'executed_gain' => $this->executedGain,
            'commit' => $this->commit,
            'error' => $this->error,
            'accepted' => $this->accepted,
            'rejected' => $this->rejected,
        ];
    }
}
