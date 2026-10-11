<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Report;

use Opmin\Module\Report\RejectionCause;
use Opmin\Module\Report\TestSetup;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The cause of a rolled-back change from the reason as the gates write it and what the run had to
 * prove a change with. The reasons are the current wordings of their sources: a changed message
 * breaks this test (and {@see self::fragmentsAreWrittenByTheirSources()}), not the advice.
 */
#[Test]
#[Covers(RejectionCause::class)]
#[Covers(TestSetup::class)]
final class RejectionCauseTest
{
    private const ROOT = __DIR__ . '/../../../..';
    private const IO = 'not proven: side effects (io): only the project\'s tests can prove the change';
    private const NO_INPUTS = 'not proven: no input could be checked (the original rejects or hangs on all of them)';
    private const HARNESS = 'not proven: the harness cannot run the function: The harness could not load the code: Trait "PlaygroundSeeds\Greets" not found';

    /**
     * @return iterable<string, array{string, bool, bool, RejectionCause}> Reason, a runner, a coverage map, the cause.
     */
    public static function reasons(): iterable
    {
        yield 'harness load, no runner' => [self::HARNESS, false, false, RejectionCause::NotProvenHarnessLoad];
        yield 'harness load before the tests' => [self::HARNESS, true, true, RejectionCause::NotProvenHarnessLoad];
        yield 'io, no runner' => [self::IO, false, false, RejectionCause::NotProvenNoRunner];
        yield 'no inputs, no runner' => [self::NO_INPUTS, false, false, RejectionCause::NotProvenNoRunner];
        yield 'io, no coverage map' => [self::IO, true, false, RejectionCause::NotProvenNoCoverageMap];
        yield 'io, no test runs it' => [self::IO, true, true, RejectionCause::NotProvenSideEffects];
        yield 'global' => ['not proven: side effects (global): only the project\'s tests can prove the change', true, true, RejectionCause::NotProvenSideEffects];
        yield 'process' => ['not proven: calls exec() on generated inputs (the harness does not start processes): only the project\'s tests can prove it', true, true, RejectionCause::NotProvenSideEffects];
        yield 'no inputs' => [self::NO_INPUTS, true, true, RejectionCause::NotProvenNoInputs];
        yield 'discarded' => ['not proven: too many inputs were discarded', true, true, RejectionCause::NotProvenNoInputs];
        yield 'timeout' => ['not proven: the original times out on generated inputs: 3 in a row ran past verification.call_timeout_ms (1000 ms)', true, true, RejectionCause::NotProvenTimeout];
        yield 'out of memory' => ['not proven: the original runs out of memory on generated inputs: 5 in a row exhausted verification.memory_limit (256M)', true, true, RejectionCause::NotProvenOther];
        yield 'out of memory or time' => ['not proven: the original runs out of memory or times out on generated inputs: 5 in a row exhausted verification.memory_limit (256M) or ran past verification.call_timeout_ms (1000 ms)', true, true, RejectionCause::NotProvenOther];
        yield 'low coverage' => ['not proven: differential tests cover 40% of the branches of the original, 80% required (verification.min_branch_coverage)', true, true, RejectionCause::NotProvenLowCoverage];
        yield 'nondeterministic' => ['not proven: nondeterministic: the original returned 1, then 2', true, true, RejectionCause::NotProvenNondeterministic];
        yield 'harness failed' => ['not proven: the harness failed: The harness ended without an answer (exit code 255).', true, true, RejectionCause::NotProvenHarnessFailed];
        yield 'harness failed, no runner' => ['not proven: the harness failed: The harness ended without an answer.', false, false, RejectionCause::NotProvenNoRunner];
        yield 'unknown coverage' => ['not proven: pcov reported no lines of the function: its coverage is unknown', true, true, RejectionCause::NotProvenOther];
        yield 'cannot be called' => ['not proven: cannot be called: an abstract method', true, true, RejectionCause::NotProvenOther];
        yield 'difference' => ['differential test: return differs: 1 vs 2', false, false, RejectionCause::DiffTest];
        yield 'difference naming a cause' => ['differential test: output "side effects (io)" vs ""', true, true, RejectionCause::DiffTest];
        yield 'docblock' => ['changes the docblock', true, true, RejectionCause::SignatureDocblock];
        yield 'shape' => ['changes the signature', true, true, RejectionCause::SignatureShape];
        yield 'fixed signature' => ['changes native types of a function with a fixed signature', true, true, RejectionCause::SignatureFixed];
        yield 'native types off' => ['changes native types (signatures.native_types: none)', true, true, RejectionCause::SignatureNativeTypes];
        yield 'public api' => ['changes native types of an overridable method (signatures.public_api)', true, true, RejectionCause::SignaturePublicApi];
        yield 'whole file' => ['the file changes outside functions, so it is all or nothing: changes the docblock', true, true, RejectionCause::SignatureDocblock];
        yield 'tests' => ['project tests fail: Tests\TextTest::slug', true, true, RejectionCause::Tests];
        yield 'phpstan' => ['new PHPStan error: Method App\f() should return int but returns string.', true, true, RejectionCause::StaticCheck];
        yield 'slower' => ['slower by 12% on 50 inputs (guard_perf.max_regression_percent 5)', true, true, RejectionCause::Performance];
        yield 'eval' => ['App\f uses eval or include: never changed', true, true, RejectionCause::Dynamic];
        yield 'readability' => ['cyclomatic complexity grew by 1 (more branches; readability.max_cyclomatic_increase is 0)', true, true, RejectionCause::Readability];
        yield 'no gain' => ['saves no opcodes', false, false, RejectionCause::NoGain];
        yield 'ignored' => ['excluded by the user (ignore)', true, true, RejectionCause::Ignored];
        yield 'review' => ['declined in the review', true, true, RejectionCause::Review];
        yield 'other' => ['adds or removes a function', true, true, RejectionCause::Other];
    }

    /**
     * @return iterable<string, array{array<string, mixed>|null, list<string>, bool, bool}>
     */
    public static function setups(): iterable
    {
        yield 'runner and map' => [['test_runner' => 'phpunit'], [], true, true];
        yield 'no runner' => [['test_runner' => null], ['No test runner found (tests.runner): the project\'s tests are not run.'], false, false];
        yield 'runner without a map' => [['test_runner' => 'phpunit'], ['No coverage map of the project\'s tests (Xdebug or pcov in php.binary): all tests are run.'], true, false];
        yield 'unknown environment' => [null, [], true, true];
    }

    #[DataProvider('reasons')]
    public function derivesTheCause(string $reason, bool $runner, bool $coverageMap, RejectionCause $expected): void
    {
        $cause = RejectionCause::of($reason, new TestSetup($runner, $coverageMap));

        Assert::same($cause, $expected);
    }

    public function fragmentsAreWrittenByTheirSources(): void
    {
        foreach (RejectionCause::FRAGMENTS as $fragment => [$cause, $file]) {
            $source = \strtolower((string) \file_get_contents(self::ROOT . '/' . $file));

            Assert::true(\str_contains($source, $fragment), "{$file} no longer writes \"{$fragment}\" ({$cause})");
        }
    }

    public function everyCauseIsOfAKind(): void
    {
        foreach (RejectionCause::cases() as $cause) {
            Assert::same($cause->kind()->value, \explode('.', $cause->value)[0]);
        }
    }

    /**
     * @param array<string, mixed>|null $environment
     * @param list<string> $notes
     */
    #[DataProvider('setups')]
    public function readsTheTestSetupOfARun(?array $environment, array $notes, bool $runner, bool $coverageMap): void
    {
        $setup = TestSetup::of($environment, $notes);

        Assert::same([$setup->runner, $setup->coverageMap], [$runner, $coverageMap]);
    }

    public function notesAreWrittenByTheVerifier(): void
    {
        $source = (string) \file_get_contents(self::ROOT . '/src/Module/Verification/Verifier.php');

        foreach ([TestSetup::NOTE_NO_RUNNER, TestSetup::NOTE_NO_COVERAGE_MAP] as $note) {
            Assert::true(\str_contains($source, \str_replace("'", "\\'", $note)), "Verifier.php no longer writes \"{$note}\"");
        }
    }
}
