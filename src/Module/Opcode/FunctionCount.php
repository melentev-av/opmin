<?php

declare(strict_types=1);

namespace Opmin\Module\Opcode;

use Opmin\Module\Opcode\Locate\UnitKind;

/**
 * Opcode count of one function, method, hook, closure or main code of a file.
 *
 * @internal
 */
final readonly class FunctionCount
{
    /**
     * @param non-empty-string $key Stable key, see {@see \Opmin\Module\Opcode\Locate\CodeUnit::$key}.
     * @param non-empty-string $file Path relative to the project root.
     * @param int<0, max> $opsRaw Opcodes before the optimizer.
     * @param int<0, max> $opsOpt Opcodes after the optimizer — the main metric.
     * @param array<non-empty-string, positive-int> $opcodes Histogram of the optimized opcodes.
     * @param bool $optimizable False for main code: it is counted, but not optimized by default.
     * @param list<non-empty-string> $flags Values of {@see \Opmin\Module\Analysis\Flag}.
     */
    public function __construct(
        public string $key,
        public UnitKind $kind,
        public string $file,
        public int $line,
        public int $opsRaw,
        public int $opsOpt,
        public int $args,
        public int $vars,
        public int $tmps,
        public array $opcodes,
        public bool $optimizable,
        public array $flags = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     * @param non-empty-string $key
     */
    public static function fromArray(string $key, array $data): self
    {
        /** @var array{kind: string, file: non-empty-string, line: int, ops_raw: int<0, max>, ops_opt: int<0, max>, args: int, vars: int, tmps: int, opcodes: array<non-empty-string, positive-int>, optimizable: bool, flags: list<non-empty-string>} $data */
        return new self(
            key: $key,
            kind: UnitKind::from($data['kind']),
            file: $data['file'],
            line: $data['line'],
            opsRaw: $data['ops_raw'],
            opsOpt: $data['ops_opt'],
            args: $data['args'],
            vars: $data['vars'],
            tmps: $data['tmps'],
            opcodes: $data['opcodes'],
            optimizable: $data['optimizable'],
            flags: $data['flags'],
        );
    }

    /**
     * @param list<non-empty-string> $flags
     */
    public function withFlags(array $flags): self
    {
        return new self(
            $this->key,
            $this->kind,
            $this->file,
            $this->line,
            $this->opsRaw,
            $this->opsOpt,
            $this->args,
            $this->vars,
            $this->tmps,
            $this->opcodes,
            $this->optimizable,
            $flags,
        );
    }

    /**
     * @return array<non-empty-string, mixed> The value of the function in the JSON report.
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'file' => $this->file,
            'line' => $this->line,
            'ops_raw' => $this->opsRaw,
            'ops_opt' => $this->opsOpt,
            'args' => $this->args,
            'vars' => $this->vars,
            'tmps' => $this->tmps,
            'optimizable' => $this->optimizable,
            'flags' => $this->flags,
            'opcodes' => $this->opcodes,
        ];
    }
}
