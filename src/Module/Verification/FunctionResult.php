<?php

declare(strict_types=1);

namespace Opmin\Module\Verification;

/**
 * Final decision for one changed function: the differential verdict combined with the project's
 * tests and `verification.allow_unverified`.
 *
 * @internal
 */
final readonly class FunctionResult
{
    /**
     * @param non-empty-string $key
     * @param 'diff-tested'|'tests'|'unverified'|'rejected' $status How the behavior is proven, or `rejected`.
     * @param list<non-empty-string> $tests Project tests that execute the function.
     */
    public function __construct(
        public string $key,
        public string $status,
        public ?Verdict $verdict,
        public string $reason = '',
        public array $tests = [],
        public ?string $counterexampleTest = null,
    ) {}

    public function accepted(): bool
    {
        return $this->status !== 'rejected';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'reason' => $this->reason,
            'tests' => $this->tests,
            'counterexample_test' => $this->counterexampleTest,
            'diff' => $this->verdict?->toArray(),
        ];
    }
}
