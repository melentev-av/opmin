<?php

declare(strict_types=1);

namespace Opmin\Module\Opcode\Report;

use Opmin\Module\Opcode\FunctionCount;

/**
 * The `count` report, also the input of `diff`.
 *
 * Deterministic JSON: no timestamps, functions and errors sorted by key, so the report of an
 * unchanged project is byte-identical and a diff of two reports in a PR is readable.
 *
 * ```json
 * {
 *     "opmin": "0.2.0", "php": "8.4.12", "php_target": "8.3", "optimizer_hash": "1f2e3d4c5b6a",
 *     "totals": {"files": 1, "functions": 1, "ops_raw": 8, "ops_opt": 5},
 *     "functions": {"App\\Svc::top": {"kind": "method", "file": "src/Svc.php", "line": 20, "ops_opt": 5, ...}},
 *     "errors": {"src/New.php": "ParseError: syntax error, ..."}
 * }
 * ```
 *
 * @internal
 */
final readonly class CountReport
{
    /**
     * @param array<non-empty-string, FunctionCount> $functions By key, sorted.
     * @param array<non-empty-string, non-empty-string> $errors File => message, sorted.
     * @param int<0, max> $files Files counted.
     */
    private function __construct(
        public string $opmin,
        public string $php,
        public ?string $phpTarget,
        public string $optimizerHash,
        public array $functions,
        public array $errors,
        public int $files,
    ) {}

    /**
     * @param list<FunctionCount> $functions
     * @param array<non-empty-string, non-empty-string> $errors
     */
    public static function create(
        string $opmin,
        string $php,
        ?string $phpTarget,
        string $optimizerHash,
        array $functions,
        array $errors = [],
    ): self {
        $byKey = [];
        foreach ($functions as $function) {
            $key = $function->key;
            # Two files can declare the same function or class (variants loaded conditionally).
            isset($byKey[$key]) and $key = "{$key}#{$function->file}";
            $byKey[$key] = $function;
        }

        \ksort($byKey, \SORT_STRING);
        \ksort($errors, \SORT_STRING);
        $files = \count(\array_unique(\array_map(static fn(FunctionCount $f): string => $f->file, $functions)));

        return new self($opmin, $php, $phpTarget, $optimizerHash, $byKey, $errors, $files);
    }

    /**
     * @throws ReportException On a file that is not a count report.
     */
    public static function fromJson(string $json, string $source): self
    {
        try {
            /** @var mixed $data */
            $data = \json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
            \is_array($data) or throw new \UnexpectedValueException('not a JSON object');
            /** @var mixed $php */
            $php = $data['php'] ?? null;
            /** @var mixed $hash */
            $hash = $data['optimizer_hash'] ?? null;
            /** @var mixed $target */
            $target = $data['php_target'] ?? null;
            \is_string($php) && \is_string($hash) && \is_array($data['functions'] ?? null)
                or throw new \UnexpectedValueException('no `php`, `optimizer_hash` or `functions`');

            $functions = [];
            /** @var array<non-empty-string, array<array-key, mixed>> $items */
            $items = $data['functions'];
            foreach ($items as $key => $values) {
                $functions[$key] = FunctionCount::fromArray($key, $values);
            }

            /** @var array<non-empty-string, non-empty-string> $errors */
            $errors = \is_array($data['errors'] ?? null) ? $data['errors'] : [];
            /** @var array{files?: int} $totals */
            $totals = \is_array($data['totals'] ?? null) ? $data['totals'] : [];
            /** @var mixed $opmin */
            $opmin = $data['opmin'] ?? '';

            return new self(
                \is_string($opmin) ? $opmin : '',
                $php,
                \is_string($target) ? $target : null,
                $hash,
                $functions,
                $errors,
                \max(0, $totals['files'] ?? 0),
            );
        } catch (\Throwable $e) {
            throw new ReportException("`{$source}` is not an opmin count report: {$e->getMessage()}", previous: $e);
        }
    }

    public function opsRaw(): int
    {
        return \array_sum(\array_map(static fn(FunctionCount $f): int => $f->opsRaw, $this->functions));
    }

    public function opsOpt(): int
    {
        return \array_sum(\array_map(static fn(FunctionCount $f): int => $f->opsOpt, $this->functions));
    }

    public function toJson(): string
    {
        $functions = [];
        foreach ($this->functions as $key => $function) {
            $functions[$key] = $function->toArray();
        }

        return \json_encode([
            'opmin' => $this->opmin,
            'php' => $this->php,
            'php_target' => $this->phpTarget,
            'optimizer_hash' => $this->optimizerHash,
            'totals' => [
                'files' => $this->files,
                'functions' => \count($this->functions),
                'ops_raw' => $this->opsRaw(),
                'ops_opt' => $this->opsOpt(),
            ],
            # Empty maps stay objects in JSON.
            'functions' => (object) $functions,
            'errors' => (object) $this->errors,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";
    }
}
