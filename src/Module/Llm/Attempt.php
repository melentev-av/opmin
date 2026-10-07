<?php

declare(strict_types=1);

namespace Opmin\Module\Llm;

/**
 * One candidate of the LLM stage and what became of it: a line of `attempts.jsonl`.
 *
 * @internal
 */
final readonly class Attempt
{
    /**
     * @param positive-int $number
     * @param non-empty-string $function
     * @param non-empty-string $file Relative to the project root.
     * @param non-empty-string $candidate The source, relative to the run directory.
     * @param string|null $before The file before an accepted attempt, relative to the run directory.
     * @param array<string, mixed>|null $counterexample The shrunk input on which the versions differ.
     */
    public function __construct(
        public int $number,
        public string $function,
        public string $file,
        public bool $accepted,
        public int $gain,
        public string $reason,
        public string $candidate,
        public ?string $status = null,
        public ?string $commit = null,
        public ?string $before = null,
        public ?array $counterexample = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array{n: positive-int, function: non-empty-string, file: non-empty-string, accepted: bool, gain: int, reason: string, candidate: non-empty-string, status?: string|null, commit?: string|null, before?: string|null, counterexample?: array<string, mixed>|null} $data */
        return new self(
            $data['n'],
            $data['function'],
            $data['file'],
            $data['accepted'],
            $data['gain'],
            $data['reason'],
            $data['candidate'],
            $data['status'] ?? null,
            $data['commit'] ?? null,
            $data['before'] ?? null,
            $data['counterexample'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return \array_filter([
            'n' => $this->number,
            'function' => $this->function,
            'file' => $this->file,
            'accepted' => $this->accepted,
            'gain' => $this->gain,
            'reason' => $this->reason,
            'status' => $this->status,
            'commit' => $this->commit,
            'candidate' => $this->candidate,
            'before' => $this->before,
            'counterexample' => $this->counterexample,
        ], static fn(mixed $v): bool => $v !== null);
    }
}
