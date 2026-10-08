<?php

declare(strict_types=1);

namespace Opmin\Module\Report;

/**
 * Why a change was rolled back, grouped for the report (brief, «Отчёт»): the reason texts come from
 * the gates of the optimizer, the verifier and the review; this sorts them.
 *
 * @internal
 */
enum RejectionKind: string
{
    # In the order of the report: what says most about the code first.
    case DiffTest = 'diff_test';
    case Tests = 'tests';
    case StaticCheck = 'static_check';
    case NotProven = 'not_proven';
    case Performance = 'performance';
    case Signature = 'signature';
    case Dynamic = 'dynamic';
    case Readability = 'readability';
    case NoGain = 'no_gain';
    case Ignored = 'ignored';
    case Review = 'review';
    case Other = 'other';

    /** Fixed starts of reasons whose rest is free text (a difference, an error message): checked first. */
    private const PREFIXES = [
        'differential test:' => 'diff_test',
        'not proven:' => 'not_proven',
        'project tests fail' => 'tests',
        'taken back: the full test run fails' => 'tests',
        'new phpstan error' => 'static_check',
        'syntax error' => 'static_check',
        'rector:' => 'static_check',
        'the candidate cannot be parsed' => 'static_check',
        'slower' => 'performance',
        'declined in the review' => 'review',
    ];

    /** Fragments of the other reasons, in the order of checking. */
    private const FRAGMENTS = [
        'ignored' => ['excluded by the user'],
        'dynamic' => ['eval or include', 'line numbers'],
        'signature' => ['changes the signature', 'changes the docblock', 'native types'],
        'readability' => ['min_gain_per_line', 'cyclomatic complexity', 'nesting grew', 'introduces '],
        'no_gain' => ['below readability.min_gain', 'opcodes grew', 'does not change', 'changes outside functions only'],
    ];

    public static function fromReason(string $reason): self
    {
        # "the file changes outside functions, so it is all or nothing: <why>" — the kind of <why>.
        $marker = 'all or nothing: ';
        $at = \strpos($reason, $marker);
        $at === false or $reason = \substr($reason, $at + \strlen($marker));
        $lower = \strtolower($reason);
        foreach (self::PREFIXES as $prefix => $kind) {
            if (\str_starts_with($lower, $prefix)) {
                return self::from($kind);
            }
        }

        foreach (self::FRAGMENTS as $kind => $fragments) {
            foreach ($fragments as $fragment) {
                if (\str_contains($lower, $fragment)) {
                    return self::from($kind);
                }
            }
        }

        return self::Other;
    }

    public function title(): string
    {
        return match ($this) {
            self::NoGain => 'no opcodes saved',
            self::Readability => 'readability thresholds',
            self::Dynamic => 'dynamic constructs',
            self::Signature => 'signature rules',
            self::Ignored => 'excluded by the user',
            self::StaticCheck => 'syntax or static analysis',
            self::Tests => 'project tests fail',
            self::DiffTest => 'differential test found a difference',
            self::NotProven => 'behavior not proven',
            self::Performance => 'slower (guard-perf)',
            self::Review => 'declined in the review',
            self::Other => 'other',
        };
    }
}
