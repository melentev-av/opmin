<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize\Review;

/**
 * One change that passed every check, as the review shows it: the diff of the function (of the whole
 * file when the step changes it outside functions), the gain, the rule, how it is proven.
 *
 * @internal
 */
final readonly class Change
{
    /**
     * @param non-empty-string $file Relative to the project root.
     * @param non-empty-list<non-empty-string> $functions Top-level functions the decision covers.
     * @param non-empty-string $rule FQCN of the rule.
     * @param non-empty-string $ruleName Alias or short name of the rule (`fqn`, `llm`).
     * @param list<array<string, mixed>> $checks {@see \Opmin\Module\Optimize\StepReport::check()} of each verified function.
     * @param string $diff Unified diff hunks.
     */
    public function __construct(
        public string $file,
        public array $functions,
        public string $rule,
        public string $ruleName,
        public int $gain,
        public string $status,
        public array $checks,
        public string $diff,
        public bool $executedGain = false,
    ) {}

    /**
     * "−4 opcodes, rule fqn, checks: diff-tested 96% (210 inputs) ✓, tests 3 ✓".
     */
    public function summary(): string
    {
        $checks = [];
        foreach ($this->checks as $check) {
            $status = (string) ($check['status'] ?? '');
            /** @var mixed $coverage */
            $coverage = $check['coverage'] ?? null;
            /** @var mixed $inputs */
            $inputs = $check['inputs'] ?? null;
            /** @var mixed $list */
            $list = $check['tests'] ?? null;
            $tests = \is_array($list) ? \count($list) : 0;
            $checks[] = match ($status) {
                'diff-tested' => \sprintf('diff-tested %s%%%s ✓', \is_float($coverage) || \is_int($coverage) ? (string) \round((float) $coverage, 1) : '?', \is_int($inputs) ? " ({$inputs} inputs)" : ''),
                'tests' => "tests {$tests} ✓",
                default => $status . ' !',
            } . ($status === 'diff-tested' && $tests > 0 ? ", tests {$tests} ✓" : '');
        }

        $gain = $this->executedGain && $this->gain === 0 ? 'fewer executed opcodes' : "−{$this->gain} opcodes";

        return \sprintf(
            '%s, rule %s, checks: %s',
            $gain,
            $this->ruleName,
            $checks === [] ? $this->status : \implode('; ', \array_unique($checks)),
        );
    }
}
