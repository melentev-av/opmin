<?php

declare(strict_types=1);

namespace Opmin\Module\Report;

/**
 * What to do next after a run: `advice` of `report.json`, the top of `report.md` and the end of the
 * console summary. Keyed on the cause of the rolled-back changes ({@see RejectionCause}), which already
 * folds in the kind and what the run had to prove a change with ({@see TestSetup}).
 *
 * Expected rollbacks (no gain, readability, excluded, declined in the review) and the ones only a
 * fix of opmin or of the code helps with get no advice: they are counted in the report. Keeping
 * unproven changes (`--allow-unverified`) is never the advice, only the last line, with a warning
 * (brief, «Режим „голые файлы“»).
 *
 * @internal
 */
final readonly class Advice
{
    /** The cause of the last-resort line: the kind, since it covers every not proven change. */
    public const string LAST_RESORT = 'not_proven';

    /**
     * @param non-empty-string $kind {@see RejectionKind} value.
     * @param non-empty-string $cause {@see RejectionCause} value, or {@see self::LAST_RESORT}.
     * @param positive-int $count Rolled-back changes it is about.
     * @param non-empty-string $text
     */
    public function __construct(
        public string $kind,
        public string $cause,
        public int $count,
        public string $text,
    ) {}

    /**
     * The advice for the rolled-back changes of a run, the most useful first.
     *
     * @param list<array<array-key, mixed>> $rejected `rejected` of `report.json` (with `kind` and `cause`).
     * @param non-empty-string|null $counterexamples Where the counterexamples of the run are; null — none written.
     * @return list<self>
     */
    public static function of(array $rejected, ?string $counterexamples): array
    {
        /** @var array<string, positive-int> $counts */
        $counts = [];
        $notProven = 0;
        foreach ($rejected as $entry) {
            $cause = RejectionCause::tryFrom((string) ($entry['cause'] ?? ''));
            $cause === null or $counts[$cause->value] = ($counts[$cause->value] ?? 0) + 1;
            ($entry['kind'] ?? null) === RejectionKind::NotProven->value and ++$notProven;
        }

        $result = [];
        foreach (RejectionCause::cases() as $cause) {
            $count = $counts[$cause->value] ?? null;
            $text = $count === null ? null : self::text($cause, $counterexamples);
            $count === null || $text === null or $result[] = new self($cause->kind()->value, $cause->value, $count, $text);
        }

        \usort($result, static fn(self $a, self $b): int => self::rank($a->cause) <=> self::rank($b->cause));
        $notProven === 0 or $result[] = new self(
            RejectionKind::NotProven->value,
            self::LAST_RESORT,
            $notProven,
            'Last resort: `--allow-unverified` keeps unproven changes. Warning: their behavior may differ, unnoticed.',
        );

        return $result;
    }

    /**
     * `advice` of `report.json`; empty for a report of an earlier opmin.
     *
     * @param array<array-key, mixed> $report
     * @return list<self>
     */
    public static function fromReport(array $report): array
    {
        $result = [];
        /** @var mixed $entry */
        foreach (\is_array($report['advice'] ?? null) ? $report['advice'] : [] as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            /** @var mixed $kind */
            $kind = $entry['kind'] ?? null;
            /** @var mixed $cause */
            $cause = $entry['cause'] ?? null;
            /** @var mixed $count */
            $count = $entry['count'] ?? null;
            /** @var mixed $text */
            $text = $entry['text'] ?? null;
            if (\is_string($kind) && $kind !== '' && \is_string($cause) && $cause !== '' && \is_int($count) && $count > 0 && \is_string($text) && $text !== '') {
                $result[] = new self($kind, $cause, $count, $text);
            }
        }

        return $result;
    }

    /**
     * @return array{kind: non-empty-string, cause: non-empty-string, count: positive-int, text: non-empty-string}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'cause' => $this->cause, 'count' => $this->count, 'text' => $this->text];
    }

    /**
     * @return non-empty-string|null
     */
    private static function text(RejectionCause $cause, ?string $counterexamples): ?string
    {
        $tests = 'add project tests that run these functions';

        return match ($cause) {
            RejectionCause::NotProvenHarnessLoad => 'The harness could not load the code: check the autoload '
                . '(`composer install`), then run `opmin doctor`.',
            RejectionCause::NotProvenNoRunner => 'Set `tests.runner`: only the project\'s tests can prove what the '
                . 'generated inputs could not.',
            RejectionCause::NotProvenNoCoverageMap => 'Enable pcov or Xdebug in `php.binary`: without a coverage map '
                . 'the project\'s tests prove no function.',
            RejectionCause::NotProvenSideEffects => 'Side effects (I/O, globals, processes) are proven only by the '
                . 'project\'s tests: ' . $tests . '.',
            RejectionCause::NotProvenNoInputs => 'The generated inputs could not call these functions: precise '
                . '`@param` types help, or ' . $tests . '.',
            RejectionCause::NotProvenTimeout => 'The original times out on the generated inputs '
                . '(`verification.call_timeout_ms`): ' . $tests . '.',
            RejectionCause::NotProvenLowCoverage => 'The generated inputs reached too little of these functions '
                . '(`verification.min_branch_coverage`): ' . $tests . '.',
            RejectionCause::NotProvenNondeterministic, RejectionCause::NotProvenOther => 'Nothing proved these '
                . 'changes: ' . $tests . '.',
            RejectionCause::NotProvenHarnessFailed => 'The harness failed while running these functions: run '
                . '`opmin doctor`; the report has the errors.',
            RejectionCause::DiffTest => 'A change behaved differently on some input: '
                . ($counterexamples === null ? 'the report shows the input' : "counterexamples in `{$counterexamples}`")
                . '. From a rule of opmin, that is a bug: please report it.',
            RejectionCause::SignaturePublicApi => 'Native types of overridable methods change only with '
                . '`--allow-public-signatures` or `signatures.public_api: true` (applications, not packages).',
            RejectionCause::SignatureNativeTypes => 'Native types change only with `signatures.native_types: '
                . 'non_overridable` (or `all`).',
            RejectionCause::SignatureDocblock => 'Docblocks change only with `signatures.phpdoc: true`.',
            default => null,
        };
    }

    private static function rank(string $cause): int
    {
        $order = [
            RejectionCause::NotProvenHarnessLoad->value,
            RejectionCause::NotProvenNoRunner->value,
            RejectionCause::NotProvenNoCoverageMap->value,
            RejectionCause::DiffTest->value,
        ];
        $at = \array_search($cause, $order, true);

        return $at === false ? \count($order) : $at;
    }
}
