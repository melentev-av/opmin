<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize;

/**
 * What one rule did in one pass: the functions it changed and kept, the ones rolled back and why.
 *
 * @internal
 */
final class StepReport
{
    /** @var list<array{file: non-empty-string, function: non-empty-string, gain: int, status: string}> */
    public array $accepted = [];

    /** @var list<array{file: non-empty-string, function: string, reason: string}> */
    public array $rejected = [];

    /** @var array<non-empty-string, string> Files the step wrote => their content before it. */
    public array $before = [];

    public ?string $commit = null;
    public ?string $error = null;

    /**
     * @param non-empty-string $rule FQCN.
     */
    public function __construct(
        public readonly string $rule,
        public readonly int $pass,
    ) {}

    public function gain(): int
    {
        return \array_sum(\array_column($this->accepted, 'gain'));
    }

    /**
     * @param non-empty-string $file
     */
    public function reject(string $file, string $function, string $reason): void
    {
        $this->rejected[] = ['file' => $file, 'function' => $function, 'reason' => $reason];
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
            'commit' => $this->commit,
            'error' => $this->error,
            'accepted' => $this->accepted,
            'rejected' => $this->rejected,
        ];
    }
}
