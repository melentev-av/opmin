<?php

declare(strict_types=1);

namespace Opmin\Module\Report;

/**
 * What to fix first so that a rolled-back change could be kept: `rejected[].cause` of `report.json`,
 * the key of the advice ({@see Advice}). A {@see RejectionKind} sorts the reasons for a reader; a cause
 * also looks at what the run had to prove a change with ({@see TestSetup}).
 *
 * The reasons are free text written by the gates of the optimizer, the verifier and the differential
 * tester; this is the only place that reads their wording ({@see self::FRAGMENTS}, pinned by tests
 * against the sources), so a changed message breaks a test, not the advice.
 *
 * Not proven: a harness that could not load the code comes first (nothing runs until it is fixed),
 * then a missing runner and a missing coverage map (only the project's tests could prove the rest),
 * then why the generated inputs did not prove the function no test runs.
 *
 * @internal
 */
enum RejectionCause: string
{
    case NotProvenHarnessLoad = 'not_proven.harness_load';
    case NotProvenHarnessFailed = 'not_proven.harness_failed';
    case NotProvenNoRunner = 'not_proven.no_runner';
    case NotProvenNoCoverageMap = 'not_proven.no_coverage_map';
    case NotProvenSideEffects = 'not_proven.side_effects';
    case NotProvenNoInputs = 'not_proven.no_inputs';
    case NotProvenTimeout = 'not_proven.timeout';
    case NotProvenLowCoverage = 'not_proven.low_coverage';
    case NotProvenNondeterministic = 'not_proven.nondeterministic';
    case NotProvenOther = 'not_proven.other';
    case DiffTest = 'diff_test';
    case Tests = 'tests';
    case StaticCheck = 'static_check';
    case Performance = 'performance';
    case SignatureDocblock = 'signature.docblock';
    case SignatureShape = 'signature.shape';
    case SignatureFixed = 'signature.fixed';
    case SignatureNativeTypes = 'signature.native_types';
    case SignaturePublicApi = 'signature.public_api';
    case Signature = 'signature';
    case Dynamic = 'dynamic';
    case Readability = 'readability';
    case NoGain = 'no_gain';
    case Ignored = 'ignored';
    case Review = 'review';
    case Other = 'other';

    /**
     * Fragments of the reasons, in the order of checking, and the file that writes each of them.
     *
     * @var array<non-empty-string, array{non-empty-string, non-empty-string}> Fragment => [cause, source file].
     */
    public const FRAGMENTS = [
        'the harness cannot run the function' => ['not_proven.harness_load', 'src/Module/Verification/DiffTester.php'],
        'the harness failed' => ['not_proven.harness_failed', 'src/Module/Verification/DiffTester.php'],
        'side effects (io)' => ['not_proven.side_effects', 'src/Module/Verification/DiffTester.php'],
        'side effects (global)' => ['not_proven.side_effects', 'src/Module/Verification/DiffTester.php'],
        'the harness does not start processes' => ['not_proven.side_effects', 'src/Module/Verification/DiffTester.php'],
        'no input could be checked' => ['not_proven.no_inputs', 'src/Module/Verification/DiffTester.php'],
        'too many inputs were discarded' => ['not_proven.no_inputs', 'src/Module/Verification/DiffTester.php'],
        'the original times out on generated inputs' => ['not_proven.timeout', 'src/Module/Verification/DiffTester.php'],
        # Memory, not time (#62): the advice on call_timeout_ms of not_proven.timeout would mislead.
        'the original runs out of memory' => ['not_proven.other', 'src/Module/Verification/DiffTester.php'],
        'of the branches of the original' => ['not_proven.low_coverage', 'src/Module/Verification/DiffTester.php'],
        'nondeterministic: ' => ['not_proven.nondeterministic', 'src/Module/Verification/DiffTester.php'],
        'changes the docblock' => ['signature.docblock', 'src/Module/Optimize/SignatureGate.php'],
        'changes the signature' => ['signature.shape', 'src/Module/Optimize/SignatureGate.php'],
        'with a fixed signature' => ['signature.fixed', 'src/Module/Optimize/SignatureGate.php'],
        '(signatures.native_types: none)' => ['signature.native_types', 'src/Module/Optimize/SignatureGate.php'],
        '(signatures.public_api)' => ['signature.public_api', 'src/Module/Optimize/SignatureGate.php'],
    ];

    public static function of(string $reason, TestSetup $setup): self
    {
        $kind = RejectionKind::fromReason($reason);
        $lower = \strtolower($reason);
        $found = null;
        foreach (self::FRAGMENTS as $fragment => [$cause]) {
            $case = self::from($cause);
            if ($case->kind() === $kind && \str_contains($lower, $fragment)) {
                $found = $case;
                break;
            }
        }

        if ($kind === RejectionKind::NotProven) {
            return match (true) {
                $found === self::NotProvenHarnessLoad => $found,
                !$setup->runner => self::NotProvenNoRunner,
                !$setup->coverageMap => self::NotProvenNoCoverageMap,
                default => $found ?? self::NotProvenOther,
            };
        }

        return $found ?? match ($kind) {
            RejectionKind::DiffTest => self::DiffTest,
            RejectionKind::Tests => self::Tests,
            RejectionKind::StaticCheck => self::StaticCheck,
            RejectionKind::Performance => self::Performance,
            RejectionKind::Signature => self::Signature,
            RejectionKind::Dynamic => self::Dynamic,
            RejectionKind::Readability => self::Readability,
            RejectionKind::NoGain => self::NoGain,
            RejectionKind::Ignored => self::Ignored,
            RejectionKind::Review => self::Review,
            RejectionKind::Other => self::Other,
        };
    }

    public function kind(): RejectionKind
    {
        return RejectionKind::from(\explode('.', $this->value, 2)[0]);
    }
}
