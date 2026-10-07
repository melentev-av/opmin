<?php

declare(strict_types=1);

namespace Opmin\Module\Llm;

/**
 * A function the LLM stage rewrites.
 *
 * @internal
 */
final readonly class Target
{
    /**
     * @param non-empty-string $key
     * @param non-empty-string $file Relative to the project root.
     */
    public function __construct(
        public string $key,
        public string $file,
        public int $line,
        public int $opsOpt,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array{key: non-empty-string, file: non-empty-string, line: int, ops_opt: int} $data */
        return new self($data['key'], $data['file'], $data['line'], $data['ops_opt']);
    }

    /**
     * @return array{key: non-empty-string, file: non-empty-string, line: int, ops_opt: int}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'file' => $this->file, 'line' => $this->line, 'ops_opt' => $this->opsOpt];
    }
}
