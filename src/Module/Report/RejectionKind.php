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
        # `below readability.min_gain`: the wording of earlier versions, still found in older reports.
        'no_gain' => ['saves no opcodes', 'saves only ', 'below readability.min_gain', 'opcodes grew', 'does not change', 'changes outside functions only'],
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
            self::Readability => 'not worth the code change',
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

    /**
     * What the group means, for a reader who has not read the config.
     */
    public function description(): string
    {
        return match ($this) {
            self::DiffTest => 'The original and the changed function were called with the same generated inputs, and on '
                . 'some input they behaved differently (result, output, exception, warning or changed arguments). '
                . 'A counterexample below shows such an input.',
            self::Tests => 'The project\'s tests failed with the change.',
            self::StaticCheck => 'The changed code does not parse, Rector failed on it, or PHPStan found a new error.',
            self::NotProven => 'Nothing proved that the function behaves as before: the generated inputs reached too little of '
                . 'its code (verification.min_branch_coverage), it has side effects or is nondeterministic, and no '
                . 'project test runs it. `--allow-unverified` keeps such changes.',
            self::Performance => '`--guard-perf` measured the changed function as slower than '
                . 'guard_perf.max_regression_percent allows.',
            self::Signature => 'The change would alter the signature or the docblock of the function, which the '
                . '`signatures.*` settings do not allow.',
            self::Dynamic => 'The function uses something that makes any rewrite unsafe (eval, include, line numbers, '
                . 'a call stack), so it is never changed.',
            self::Readability => 'The change saved opcodes and behaved the same, but it makes the code harder to read than '
                . 'the gain is worth (`readability.*`): too few opcodes saved per changed line, more branches or deeper '
                . 'nesting, or a forbidden construct.',
            self::NoGain => 'The change saved no opcodes, or added some.',
            self::Ignored => 'The function is excluded from optimization by the config (`ignore.*`) or a mark in the code '
                . '(`@opmin-ignore`, `#[\\Opmin\\Ignore]`).',
            self::Review => 'Declined in `--review`; opmin.baseline.yaml remembers it, and it is not proposed again.',
            self::Other => 'Other reasons, as the column says.',
        };
    }
}
