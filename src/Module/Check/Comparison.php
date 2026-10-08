<?php

declare(strict_types=1);

namespace Opmin\Module\Check;

use Opmin\Module\Opcode\Report\CountReport;

/**
 * The counts of the checked files compared with the baseline, function by function (`ops_opt`).
 *
 * Functions are matched by their stable key, so a function moved to another file is still the same
 * function, and a closure keeps its key when lines above it change.
 *
 * @internal
 */
final readonly class Comparison
{
    /**
     * @param list<Finding> $findings Errors first, then warnings, then the rest; by file and key inside.
     * @param int<0, max> $checked Functions of the checked files.
     * @param int<0, max> $unchanged
     * @param array<non-empty-string, array{ops: int<0, max>, file: non-empty-string}|null> $updates What
     *        `--update-baseline` writes: decreased, new, moved and removed functions (null — removed).
     *        Grown functions are not updated: accepting growth is an explicit `opmin baseline`.
     */
    private function __construct(
        public array $findings,
        public int $checked,
        public int $unchanged,
        public array $updates,
    ) {}

    /**
     * @param list<non-empty-string>|null $scope Relative paths of the checked files; null — the whole
     *        project. A baseline function of a file outside the scope is not looked at.
     * @param int<0, max> $tolerance
     * @param positive-int|null $limit `check.max_ops_new_function`.
     */
    public static function compare(Baseline $baseline, CountReport $current, ?array $scope, int $tolerance, ?int $limit): self
    {
        $findings = $updates = $matched = [];
        $checked = $unchanged = 0;
        foreach ($current->functions as $key => $function) {
            if (!$function->optimizable) {
                continue;
            }

            ++$checked;
            # A function declared in two files (polyfills) gets `#file` in one of them, and which one
            # depends on the files counted: the changed ones only, or all. The file decides.
            foreach (["{$function->key}#{$function->file}", $function->key] as $candidate) {
                if (isset($baseline->functions[$candidate]) && !isset($matched[$candidate])) {
                    $key = $candidate;
                    break;
                }
            }

            $old = $baseline->functions[$key] ?? null;
            $matched[$key] = true;
            $line = \max(1, $function->line);
            $ops = $function->opsOpt;
            if ($old === null) {
                $findings[] = new Finding(
                    $limit !== null && $ops > $limit ? FindingKind::NewOverLimit : FindingKind::New,
                    $key,
                    $function->file,
                    $line,
                    null,
                    $ops,
                );
                $updates[$key] = Baseline::entry($function);
                continue;
            }

            $delta = $ops - $old['ops'];
            if ($delta > $tolerance) {
                $findings[] = new Finding(FindingKind::Grown, $key, $function->file, $line, $old['ops'], $ops);
                $old['file'] === $function->file or $updates[$key] = ['ops' => $old['ops'], 'file' => $function->file];
                continue;
            }

            if ($delta < 0) {
                $findings[] = new Finding(FindingKind::Decreased, $key, $function->file, $line, $old['ops'], $ops);
                $updates[$key] = Baseline::entry($function);
                continue;
            }

            ++$unchanged;
            # Growth within the tolerance is not written: it would let the tolerance accumulate.
            $old['file'] === $function->file or $updates[$key] = ['ops' => $old['ops'], 'file' => $function->file];
        }

        $inScope = $scope === null ? null : \array_flip($scope);
        foreach ($baseline->functions as $key => $old) {
            if (isset($matched[$key]) || ($inScope !== null && !isset($inScope[$old['file']]))) {
                continue;
            }

            $findings[] = new Finding(FindingKind::Removed, $key, $old['file'], null, $old['ops'], null);
            $updates[$key] = null;
        }

        $order = [FindingKind::Grown, FindingKind::NewOverLimit, FindingKind::Decreased, FindingKind::New, FindingKind::Removed];
        \usort($findings, static fn(Finding $a, Finding $b): int => [\array_search($a->kind, $order, true), $a->file, $a->line ?? 0, $a->key]
            <=> [\array_search($b->kind, $order, true), $b->file, $b->line ?? 0, $b->key]);

        return new self($findings, $checked, $unchanged, $updates);
    }

    /**
     * @param \Closure(Finding): ?Suggestion $suggest
     */
    public function withSuggestions(\Closure $suggest): self
    {
        $findings = \array_map(
            static fn(Finding $f): Finding => $f->kind === FindingKind::Grown ? $f->withSuggestion($suggest($f)) : $f,
            $this->findings,
        );

        return new self($findings, $this->checked, $this->unchanged, $this->updates);
    }

    public function failed(): bool
    {
        return $this->count(FindingKind::Grown) > 0;
    }

    /**
     * @return int<0, max>
     */
    public function count(FindingKind $kind): int
    {
        return \count(\array_filter($this->findings, static fn(Finding $f): bool => $f->kind === $kind));
    }

    /**
     * @return list<Finding>
     */
    public function of(FindingKind ...$kinds): array
    {
        return \array_values(\array_filter($this->findings, static fn(Finding $f): bool => \in_array($f->kind, $kinds, true)));
    }
}
