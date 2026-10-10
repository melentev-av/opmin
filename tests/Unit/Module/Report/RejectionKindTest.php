<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Report;

use Opmin\Module\Report\RejectionKind;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The reasons the optimizer, its gates and the verifier write, sorted into the groups of the report.
 */
#[Test]
#[Covers(RejectionKind::class)]
final class RejectionKindTest
{
    /**
     * @return iterable<string, array{string, RejectionKind}>
     */
    public static function reasons(): iterable
    {
        yield 'no gain' => ['saves no opcodes', RejectionKind::NoGain];
        yield 'too little gain' => ['saves only 1 opcode(s), readability.min_gain is 2', RejectionKind::NoGain];
        yield 'no gain, earlier wording' => ['gain 0 is below readability.min_gain', RejectionKind::NoGain];
        yield 'grew' => ['opcodes grew by 2', RejectionKind::NoGain];
        yield 'same candidate' => ['the candidate does not change the function', RejectionKind::NoGain];
        yield 'whole file grew' => ['the file changes outside functions, so it is all or nothing: opcodes grew in App\f, App\g', RejectionKind::NoGain];
        yield 'outside functions only' => ['the file changes outside functions, so it is all or nothing: changes outside functions only', RejectionKind::NoGain];
        yield 'gain per line' => ['saves 1 opcode(s) for 4 changed line(s), less than readability.min_gain_per_line 0.5 per line', RejectionKind::Readability];
        yield 'gain per line, earlier wording' => ['gain 1 for 4 changed line(s) is below readability.min_gain_per_line 0.5', RejectionKind::Readability];
        yield 'complexity' => ['cyclomatic complexity grew by 1 (more branches; readability.max_cyclomatic_increase is 0)', RejectionKind::Readability];
        yield 'closure nesting' => ['App\f::{closure#1}: nesting grew by 1 (code more levels deep; readability.max_nesting_increase is 0)', RejectionKind::Readability];
        yield 'pattern' => ['introduces nested_ternary (forbidden by readability.forbid_patterns)', RejectionKind::Readability];
        yield 'ignored' => ['excluded by the user (ignore)', RejectionKind::Ignored];
        yield 'eval' => ['App\f uses eval or include: never changed', RejectionKind::Dynamic];
        yield 'line numbers' => ['App\f depends on line numbers: never changed', RejectionKind::Dynamic];
        yield 'moved line' => ['the change moves App\g, which depends on line numbers', RejectionKind::Dynamic];
        yield 'signature' => ['changes the signature', RejectionKind::Signature];
        yield 'docblock' => ['changes the docblock', RejectionKind::Signature];
        yield 'native types' => ['changes native types of a function with a fixed signature', RejectionKind::Signature];
        yield 'difference' => ['differential test: return differs: 1 vs 2', RejectionKind::DiffTest];
        yield 'difference naming other words' => ['differential test: output "slower than the signature" vs ""', RejectionKind::DiffTest];
        yield 'not proven' => ['not proven: side effects (io): only the project\'s tests can prove the change', RejectionKind::NotProven];
        yield 'tests' => ['project tests fail: Tests\TextTest::slug', RejectionKind::Tests];
        yield 'full run' => ['taken back: the full test run fails', RejectionKind::Tests];
        yield 'whole file tests' => ['the file changes outside functions, so it is all or nothing: project tests fail: T', RejectionKind::Tests];
        yield 'phpstan' => ['new PHPStan error: Method App\f() should return int but returns string.', RejectionKind::StaticCheck];
        yield 'syntax' => ['syntax error after the rule: unexpected token', RejectionKind::StaticCheck];
        yield 'rector' => ['Rector: could not process src/A.php', RejectionKind::StaticCheck];
        yield 'candidate not parsed' => ['the candidate cannot be parsed as the function App\f', RejectionKind::StaticCheck];
        yield 'slower' => ['slower by 12% (guard_perf.max_regression_percent 5)', RejectionKind::Performance];
        yield 'review' => ['declined in the review', RejectionKind::Review];
        yield 'review earlier' => ['declined in the review earlier (opmin.baseline.yaml)', RejectionKind::Review];
        yield 'adds a function' => ['adds or removes a function', RejectionKind::Other];
        yield 'not counted' => ['App\f is not counted', RejectionKind::Other];
        yield 'more than the function' => ['the candidate changes more than App\f: App\g', RejectionKind::Other];
    }

    #[DataProvider('reasons')]
    public function sortsReason(string $reason, RejectionKind $expected): void
    {
        Assert::same(RejectionKind::fromReason($reason), $expected);
    }

    public function everyKindHasATitle(): void
    {
        $titles = \array_map(static fn(RejectionKind $k): string => $k->title(), RejectionKind::cases());

        Assert::same(\count(\array_unique($titles)), \count(RejectionKind::cases()));
    }

    public function everyKindHasADescription(): void
    {
        $descriptions = \array_map(static fn(RejectionKind $k): string => $k->description(), RejectionKind::cases());

        Assert::same(\count(\array_unique($descriptions)), \count(RejectionKind::cases()));
    }
}
