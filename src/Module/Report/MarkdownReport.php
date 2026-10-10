<?php

declare(strict_types=1);

namespace Opmin\Module\Report;

use Opmin\Module\Analysis\Flag;

/**
 * `report.md`: `report.json` for people (brief, «Отчёт») — the total, the kept functions with what
 * saved their opcodes and how they are proven, the rolled-back changes grouped by reason with their
 * counterexamples, the environment of the run.
 *
 * @internal
 */
final class MarkdownReport
{
    /** Rows per group of rolled-back changes; the rest is in `report.json`. */
    private const MAX_ROWS = 100;

    /**
     * @param array<string, mixed> $report The data of `report.json`.
     */
    public static function render(array $report, string $title): string
    {
        $out = [
            "# {$title}",
            '',
            'opmin rewrote functions so that PHP compiles them to fewer opcodes, and kept a change only when it',
            'saved opcodes and the function behaved exactly as before. Opcodes are counted per function with',
            'OPcache of `php.binary` (see Environment).',
            '',
        ];
        /** @var array<string, int|float> $totals */
        $totals = \is_array($report['totals'] ?? null) ? $report['totals'] : [];
        $before = (int) ($totals['ops_before'] ?? 0);
        $after = (int) ($totals['ops_after'] ?? 0);
        $out[] = \sprintf(
            '**%d → %d opcodes** (%s) in %d function(s); %d change(s) rolled back.',
            $before,
            $after,
            $before === $after ? 'no change' : \sprintf('−%d, −%s%%', $before - $after, self::number((float) ($totals['percent'] ?? 0))),
            (int) ($totals['functions_changed'] ?? 0),
            (int) ($totals['changes_rolled_back'] ?? 0),
        );
        $out[] = '';
        $out[] = '- Full run of the project\'s tests: ' . match ($report['final_tests'] ?? null) {
            true => 'passes',
            false => '**fails** (the steps were taken back from the last one while it failed)',
            default => 'not run (no test runner, or nothing changed)',
        } . '.';
        /** @var mixed $patch */
        $patch = $report['patch'] ?? null;
        \is_string($patch) and $out[] = '- Patch: `' . $patch . '`.';
        ($report['interrupted'] ?? false) === true and $out[] = '- **The run was interrupted**: `opmin optimize --resume` continues it.';
        $out[] = '';

        $warnings = self::strings($report['warnings'] ?? null);
        if ($warnings !== []) {
            $out[] = '> [!WARNING]';
            foreach ($warnings as $warning) {
                $out[] = '> ' . $warning;
            }

            $out[] = '';
        }

        self::functions($out, $report);
        self::executed($out, $report);
        self::rejected($out, $report);
        self::counterexamples($out, $report);
        self::environment($out, $report);
        # The formatter is already in Environment.
        /** @var mixed $formatter */
        $formatter = \is_array($report['environment'] ?? null) ? ($report['environment']['formatter'] ?? null) : null;
        $notes = \array_values(\array_filter(
            self::strings($report['notes'] ?? null),
            static fn(string $note): bool => !\is_string($formatter) || $note !== "Formatter: {$formatter}",
        ));
        if ($notes !== []) {
            $out[] = '## Notes';
            $out[] = '';
            foreach ($notes as $note) {
                $out[] = '- ' . $note;
            }

            $out[] = '';
        }

        return \rtrim(\implode("\n", $out)) . "\n";
    }

    /**
     * @param list<string> $out
     * @param array<string, mixed> $report
     */
    private static function functions(array &$out, array $report): void
    {
        /** @var array<string, array<string, mixed>> $functions */
        $functions = \is_array($report['functions'] ?? null) ? $report['functions'] : [];
        if ($functions === []) {
            return;
        }

        $runner = \is_array($report['environment'] ?? null) && \is_string($report['environment']['test_runner'] ?? null);
        \uksort($functions, static fn(string $a, string $b): int => [(int) ($functions[$b]['saved'] ?? 0), $a] <=> [(int) ($functions[$a]['saved'] ?? 0), $b]);
        $out[] = '## Changed functions';
        $out[] = '';
        $out[] = '| Function | Opcodes before → after | Saved by | Proven by | Behavior check | Project tests | Flags |';
        $out[] = '|---|---|---|---|---|---|---|';
        foreach ($functions as $key => $function) {
            /** @var list<array<string, mixed>> $gains */
            $gains = \is_array($function['gains'] ?? null) ? $function['gains'] : [];
            $by = [];
            foreach ($gains as $gain) {
                $by[] = self::rule((string) ($gain['rule'] ?? ''));
            }

            $coverage = \is_float($function['diff_coverage'] ?? null) || \is_int($function['diff_coverage'] ?? null) ? (float) $function['diff_coverage'] : null;
            $out[] = '| ' . \implode(' | ', [
                self::code($key) . '<br>' . self::cell((string) ($function['file'] ?? '')),
                \sprintf('%s → %s', (string) ($function['ops_before'] ?? '?'), (string) ($function['ops_after'] ?? '?'))
                    . (($function['executed_gain'] ?? false) === true && (int) ($function['saved'] ?? 0) === 0 ? ' (executed: fewer)' : ''),
                self::cell(\implode(', ', \array_unique($by))),
                self::cell((string) ($function['status'] ?? '')) . self::time($function['time_change_percent'] ?? null),
                $coverage !== null ? (\is_int($function['inputs'] ?? null) ? "{$function['inputs']} inputs, " : '') . self::number($coverage) . '% of the original reached' : '—',
                $runner ? (string) \count(self::strings($function['tests'] ?? null)) : '—',
                self::cell(\implode(', ', self::strings($function['flags'] ?? null))),
            ]) . ' |';
        }

        $out[] = '';
        $out[] = '- **Saved by**: the rules whose changes were kept; "LLM" is a rewrite by the LLM stage.';
        $out[] = '- **Proven by**: `diff-tested` — the original and the changed function were called with the same';
        $out[] = '  generated inputs and behaved the same (result, output, exceptions, warnings, changed arguments);';
        $out[] = '  `tests` — the generated inputs could not prove it (side effects, too little of the code reached),';
        $out[] = '  the project\'s tests that run the function pass; `unverified` — not proven, kept because';
        $out[] = '  `verification.allow_unverified` is on. "time ±N%" is the speed change measured by `--guard-perf`.';
        $out[] = '- **Behavior check**: how many generated inputs both versions got, and how much of the original';
        $out[] = '  function\'s code those inputs reached. Below `verification.min_branch_coverage` the generated inputs';
        $out[] = '  alone do not prove a change. "—": not checked this way.';
        $out[] = '- **Project tests**: how many of the project\'s tests run the function; "—": no test runner.';
        self::flagLegend($out, $functions);
        $out[] = '';
    }

    /**
     * What the flags in the table mean; only the flags that occur.
     *
     * @param list<string> $out
     * @param array<string, array<string, mixed>> $functions
     */
    private static function flagLegend(array &$out, array $functions): void
    {
        $seen = [];
        foreach ($functions as $function) {
            foreach (self::strings($function['flags'] ?? null) as $value) {
                $seen[$value] = true;
            }
        }

        if ($seen === []) {
            return;
        }

        $out[] = '- **Flags**: what in the function or the project limits the rewrites; the function:';
        foreach (Flag::cases() as $flag) {
            isset($seen[$flag->value]) and $out[] = "  - `{$flag->value}` — {$flag->description()};";
            unset($seen[$flag->value]);
        }

        # A flag of a newer opmin, unknown here.
        foreach (\array_keys($seen) as $value) {
            $out[] = "  - `{$value}`;";
        }

        $last = \count($out) - 1;
        $out[$last] = \rtrim($out[$last], ';') . '.';
    }

    /**
     * @param list<string> $out
     * @param array<string, mixed> $report
     */
    private static function executed(array &$out, array $report): void
    {
        /** @var array<string, array<string, mixed>> $functions */
        $functions = \is_array($report['functions'] ?? null) ? $report['functions'] : [];
        $executed = \array_keys(\array_filter($functions, static fn(array $f): bool => ($f['executed_gain'] ?? false) === true));
        if ($executed === []) {
            return;
        }

        $out[] = '## Fewer executed opcodes, not static ones';
        $out[] = '';
        $out[] = 'These changes (`HoistLoopInvariantCountRector`: an invariant out of a loop condition) may keep the static';
        $out[] = 'count, but fewer opcodes run per loop iteration.';
        $out[] = '';
        foreach ($executed as $key) {
            $out[] = '- ' . self::code($key);
        }

        $out[] = '';
    }

    /**
     * @param list<string> $out
     * @param array<string, mixed> $report
     */
    private static function rejected(array &$out, array $report): void
    {
        /** @var list<array<string, mixed>> $rejected */
        $rejected = \is_array($report['rejected'] ?? null) ? $report['rejected'] : [];
        if ($rejected === []) {
            return;
        }

        $groups = [];
        foreach ($rejected as $entry) {
            $groups[(string) ($entry['kind'] ?? 'other')][] = $entry;
        }

        $out[] = '## Rolled back';
        $out[] = '';
        $out[] = 'Changes that were tried and taken back: the code of these functions stays as it was, or as an earlier';
        $out[] = 'kept change left it. Grouped by the reason.';
        $out[] = '';
        foreach (RejectionKind::cases() as $kind) {
            $group = $groups[$kind->value] ?? [];
            if ($group === []) {
                continue;
            }

            $out[] = \sprintf('### %s (%d)', \ucfirst($kind->title()), \count($group));
            $out[] = '';
            $out[] = $kind->description();
            $out[] = '';
            $out[] = '| Function | Rule | Reason |';
            $out[] = '|---|---|---|';
            /** @var list<array{string, array<string, mixed>}> $proofs */
            $proofs = [];
            foreach (\array_slice($group, 0, self::MAX_ROWS) as $entry) {
                $function = (string) ($entry['function'] ?? '');
                $out[] = '| ' . \implode(' | ', [
                    ($function === '' ? '' : self::code($function) . '<br>') . self::cell((string) ($entry['file'] ?? '')),
                    self::cell(self::rule((string) ($entry['rule'] ?? ''))),
                    self::cell((string) ($entry['reason'] ?? '')),
                ]) . ' |';
                /** @var array<string, mixed>|null $counterexample */
                $counterexample = \is_array($entry['counterexample'] ?? null) ? $entry['counterexample'] : null;
                $counterexample === null or $proofs[] = [$function, $counterexample];
            }

            \count($group) > self::MAX_ROWS and $out[] = \sprintf('| … %d more in report.json | | |', \count($group) - self::MAX_ROWS);
            $out[] = '';
            foreach ($proofs as [$function, $counterexample]) {
                /** @var mixed $test */
                $test = $counterexample['test'] ?? null;
                $out[] = \sprintf('Counterexample for %s%s:', self::code($function), \is_string($test) ? " (test: `{$test}`)" : '');
                $out[] = '';
                $out[] = '```json';
                $out[] = (string) \json_encode(\array_diff_key($counterexample, ['test' => true]), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
                $out[] = '```';
                $out[] = '';
            }
        }
    }

    /**
     * @param list<string> $out
     * @param array<string, mixed> $report
     */
    private static function counterexamples(array &$out, array $report): void
    {
        $files = self::strings($report['counterexamples'] ?? null);
        if ($files === []) {
            return;
        }

        $out[] = '## Counterexamples';
        $out[] = '';
        $out[] = 'Inputs on which a change behaved differently, as tests for the project\'s runner and as JSON:';
        $out[] = '';
        foreach ($files as $file) {
            $out[] = "- `{$file}`";
        }

        $out[] = '';
    }

    /**
     * @param list<string> $out
     * @param array<string, mixed> $report
     */
    private static function environment(array &$out, array $report): void
    {
        /** @var array<string, mixed> $environment */
        $environment = \is_array($report['environment'] ?? null) ? $report['environment'] : [];
        if ($environment === []) {
            return;
        }

        $labels = [
            'php' => 'PHP runtime (`php.binary`)',
            'php_target' => '`php.target`',
            'optimizer_hash' => 'OPcache optimizer settings',
            'opmin' => 'opmin',
            'rector' => 'Rector',
            'phpstan' => 'PHPStan of the project',
            'test_runner' => 'Test runner',
            'formatter' => 'Formatter',
        ];
        $out[] = '## Environment';
        $out[] = '';
        $out[] = 'What the counts and checks depend on: opcode counts are comparable only between runs with the same';
        $out[] = 'PHP version and OPcache optimizer settings.';
        $out[] = '';
        $out[] = '| | |';
        $out[] = '|---|---|';
        foreach ($labels as $field => $label) {
            /** @var mixed $value */
            $value = $environment[$field] ?? null;
            $out[] = "| {$label} | " . (\is_scalar($value) && (string) $value !== '' ? self::cell((string) $value) : '—') . ' |';
        }

        $out[] = '';
    }

    /**
     * ", time +3.1%" when `--guard-perf` measured the change.
     */
    private static function time(mixed $percent): string
    {
        return \is_float($percent) || \is_int($percent) ? \sprintf(', time %+.1f%%', (float) $percent) : '';
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return \is_array($value) ? \array_values(\array_map('strval', \array_filter($value, 'is_scalar'))) : [];
    }

    /**
     * Short name of a rule; the candidates of the LLM stage are "LLM".
     */
    private static function rule(string $class): string
    {
        $at = \strrpos($class, '\\');
        $short = $at === false ? $class : \substr($class, $at + 1);

        return $short === 'LlmCandidate' ? 'LLM' : $short;
    }

    private static function code(string $text): string
    {
        return '`' . \str_replace(['`', '|'], ["'", '\|'], $text) . '`';
    }

    private static function cell(string $text): string
    {
        return \str_replace(['|', "\r\n", "\n"], ['\|', ' ', ' '], $text);
    }

    private static function number(float $value): string
    {
        return \rtrim(\rtrim(\number_format($value, 2, '.', ''), '0'), '.');
    }
}
