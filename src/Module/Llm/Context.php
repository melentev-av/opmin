<?php

declare(strict_types=1);

namespace Opmin\Module\Llm;

use Opmin\Module\Analysis\Flag;
use Opmin\Module\Config\Schema;
use Opmin\Module\Opcode\FunctionCount;
use Opmin\Module\Optimize\Readability;
use Opmin\Module\Optimize\Units;

/**
 * Everything the model sees about one function (brief, «Stage B», step 2): the source, the opcodes
 * of the function and its closures, the dynamic constructs with what they forbid, the readability
 * and signature rules, and the attempts rejected so far with their reasons.
 *
 * @internal
 */
final readonly class Context
{
    /**
     * @param array<non-empty-string, FunctionCount> $counts The function and its closures, by key.
     * @param array<non-empty-string, string> $listings Key => optimized opcode listing.
     * @param list<Attempt> $attempts Attempts on this function so far.
     */
    public function __construct(
        private Target $target,
        private Units $units,
        private array $counts,
        private array $listings,
        private array $attempts,
        private int $attemptsAllowed,
        private Schema\Readability $readability,
        private Schema\Signatures $signatures,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $key = $this->target->key;
        $functions = [];
        $flags = [];
        foreach ($this->units->family($key) as $member) {
            $count = $this->counts[$member] ?? null;
            $functions[$member] = $count === null ? null : [
                'ops_opt' => $count->opsOpt,
                'ops_raw' => $count->opsRaw,
                'vars' => $count->vars,
                'tmps' => $count->tmps,
                'opcodes' => $count->opcodes,
                'listing' => $this->listings[$member] ?? null,
            ];
            foreach (Flag::fromValues($count?->flags ?? []) as $flag) {
                $flags[$flag->value] = $flag;
            }
        }

        $restrictions = [];
        foreach (Flag::restrictionsOf($flags) as $restriction) {
            $restrictions[$restriction->value] = $restriction->instruction();
        }

        return [
            'function' => $key,
            'file' => $this->target->file,
            'line' => $this->units->units[$key]->line,
            'ops_opt' => \array_sum(\array_map(static fn(?array $f): int => (int) ($f['ops_opt'] ?? 0), $functions)),
            'source' => $this->source(),
            'functions' => $functions,
            'flags' => \array_map(static fn(Flag $f): string => $f->describe(), $flags),
            'restrictions' => $restrictions,
            'readability' => [
                'min_gain' => $this->readability->minGain,
                'min_gain_per_line' => $this->readability->minGainPerLine,
                'max_cyclomatic_increase' => $this->readability->maxCyclomaticIncrease,
                'max_nesting_increase' => $this->readability->maxNestingIncrease,
                'forbid_patterns' => \array_values(\array_intersect($this->readability->forbidPatterns, Readability::PATTERNS)),
            ],
            'signatures' => [
                'phpdoc' => $this->signatures->phpdoc,
                'native_types' => $this->signatures->nativeTypes->value,
                'public_api' => $this->signatures->publicApi,
            ],
            'attempts' => \array_map(static fn(Attempt $a): array => [
                'n' => $a->number,
                'accepted' => $a->accepted,
                'gain' => $a->gain,
                'reason' => $a->reason,
                'candidate' => $a->candidate,
            ] + ($a->counterexample === null ? [] : ['counterexample' => $a->counterexample]), $this->attempts),
            'attempts_left' => $this->attemptsLeft(),
        ];
    }

    public function attemptsLeft(): int
    {
        return \max(0, $this->attemptsAllowed - \count($this->attempts));
    }

    /**
     * The context as Markdown, for the prompt.
     */
    public function render(): string
    {
        $data = $this->toArray();
        /** @var array<string, array{ops_opt: int, ops_raw: int, vars: int, tmps: int, opcodes: array<string, int>, listing: string|null}|null> $functions */
        $functions = $data['functions'];
        /** @var array<string, string> $flags */
        $flags = $data['flags'];
        /** @var array<string, string> $restrictions */
        $restrictions = $data['restrictions'];
        /** @var array<string, mixed> $readability */
        $readability = $data['readability'];
        /** @var list<string> $forbidden */
        $forbidden = $readability['forbid_patterns'];

        $out = [];
        $out[] = \sprintf('# %s', $this->target->key);
        $out[] = '';
        $out[] = \sprintf('%s:%d, %d opcodes after the optimizer.', $this->target->file, (int) $data['line'], (int) $data['ops_opt']);
        $out[] = '';
        $out[] = '## Source';
        $out[] = '';
        $out[] = '```php';
        $out[] = (string) $data['source'];
        $out[] = '```';
        foreach ($functions as $key => $function) {
            $out[] = '';
            $out[] = "## Opcodes of {$key}";
            $out[] = '';
            if ($function === null) {
                $out[] = 'Not counted.';
                continue;
            }

            $out[] = \sprintf('ops_opt=%d, ops_raw=%d, vars=%d, tmps=%d', $function['ops_opt'], $function['ops_raw'], $function['vars'], $function['tmps']);
            $out[] = '';
            \arsort($function['opcodes']);
            $out[] = 'Histogram: ' . \implode(', ', \array_map(
                static fn(string $op, int $n): string => "{$op} {$n}",
                \array_keys($function['opcodes']),
                $function['opcodes'],
            ));
            if ($function['listing'] !== null) {
                $out[] = '';
                $out[] = '```';
                $out[] = $function['listing'];
                $out[] = '```';
            }
        }

        $out[] = '';
        $out[] = '## Dynamic constructs';
        $out[] = '';
        if ($flags === []) {
            $out[] = 'None.';
        }

        foreach ($flags as $flag => $description) {
            $out[] = "- `{$flag}`: {$description}";
        }

        foreach ($restrictions as $instruction) {
            $out[] = "- **{$instruction}**";
        }

        $out[] = '';
        $out[] = '## Acceptance rules';
        $out[] = '';
        $out[] = \sprintf('- Saves at least %d opcode(s), and at least %s opcodes per changed line.', (int) $readability['min_gain'], (string) $readability['min_gain_per_line']);
        $out[] = \sprintf(
            '- Cyclomatic complexity may grow by %d, nesting depth by %d.',
            (int) $readability['max_cyclomatic_increase'],
            (int) $readability['max_nesting_increase'],
        );
        $forbidden === [] or $out[] = '- Forbidden constructs: ' . \implode(', ', $forbidden) . '.';
        $out[] = \sprintf(
            '- Signature: parameter names and defaults never change; native types: %s%s; phpdoc refinement %s.',
            $this->signatures->nativeTypes->value,
            $this->signatures->publicApi ? '' : ' (never on public overridable methods)',
            $this->signatures->phpdoc ? 'allowed' : 'forbidden',
        );
        $out[] = '- Behavior is checked: PHPStan, the project\'s tests and differential tests against the original.';

        $out[] = '';
        $out[] = '## Attempts';
        $out[] = '';
        if ($this->attempts === []) {
            $out[] = 'None yet.';
        }

        foreach ($this->attempts as $attempt) {
            $out[] = \sprintf(
                '- #%d %s: %s (source: %s)',
                $attempt->number,
                $attempt->accepted ? \sprintf('accepted, -%d', $attempt->gain) : 'rejected',
                $attempt->reason,
                $attempt->candidate,
            );
            /** @var mixed $input */
            $input = $attempt->counterexample['input'] ?? null;
            $input === null or $out[] = '  counterexample input: ' . \json_encode($input, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        }

        $out[] = '';
        $out[] = \sprintf('Attempts left: %d.', $this->attemptsLeft());

        return \implode("\n", $out) . "\n";
    }

    /**
     * Source of the function with its docblock.
     */
    private function source(): string
    {
        $doc = $this->units->docComment($this->target->key);
        $indent = $this->units->indent($this->target->key);

        return $indent . ($doc === null ? '' : $doc . "\n" . $indent) . (string) $this->units->source($this->target->key);
    }
}
