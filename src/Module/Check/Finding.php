<?php

declare(strict_types=1);

namespace Opmin\Module\Check;

/**
 * One function `opmin check` reports.
 *
 * @internal
 */
final readonly class Finding
{
    /**
     * @param non-empty-string $key
     * @param non-empty-string $file Relative to the project root: where the function is now, or where
     *        the baseline saw it last for a removed one.
     * @param positive-int|null $line Null for a removed function.
     * @param int<0, max>|null $before Null for a new function.
     * @param int<0, max>|null $after Null for a removed function.
     */
    public function __construct(
        public FindingKind $kind,
        public string $key,
        public string $file,
        public ?int $line,
        public ?int $before,
        public ?int $after,
        public ?Suggestion $suggestion = null,
    ) {}

    public function withSuggestion(?Suggestion $suggestion): self
    {
        return new self($this->kind, $this->key, $this->file, $this->line, $this->before, $this->after, $suggestion);
    }

    public function delta(): int
    {
        return ($this->after ?? 0) - ($this->before ?? 0);
    }

    /**
     * @param int<0, max> $tolerance
     * @param positive-int|null $limit `check.max_ops_new_function`.
     */
    public function message(int $tolerance = 0, ?int $limit = null): string
    {
        $message = match ($this->kind) {
            FindingKind::Grown => \sprintf(
                '%s: opcodes grew %d → %d (+%d%s)',
                $this->key,
                (int) $this->before,
                (int) $this->after,
                $this->delta(),
                $tolerance > 0 ? ", tolerance {$tolerance}" : '',
            ),
            FindingKind::NewOverLimit => \sprintf(
                '%s: new function with %d opcodes, above the limit of %d for new functions',
                $this->key,
                (int) $this->after,
                (int) $limit,
            ),
            FindingKind::Decreased => \sprintf(
                '%s: opcodes decreased %d → %d (%d), update the baseline',
                $this->key,
                (int) $this->before,
                (int) $this->after,
                $this->delta(),
            ),
            FindingKind::New => \sprintf('%s: new function with %d opcodes', $this->key, (int) $this->after),
            FindingKind::Removed => \sprintf('%s: removed or renamed (had %d opcodes)', $this->key, (int) $this->before),
        };

        return $this->suggestion === null ? $message : "{$message}; {$this->suggestion->describe()}";
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'severity' => $this->kind->severity()->value,
            'function' => $this->key,
            'file' => $this->file,
            'line' => $this->line,
            'before' => $this->before,
            'after' => $this->after,
            'delta' => $this->delta(),
            'suggestion' => $this->suggestion?->toArray(),
        ];
    }
}
