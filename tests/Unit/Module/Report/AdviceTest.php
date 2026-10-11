<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Report;

use Opmin\Module\Report\Advice;
use Opmin\Module\Report\ConsoleSummary;
use Opmin\Module\Report\RejectionCause;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * What a run advises from the causes of its rolled-back changes: the setting that would keep them, a
 * count only for the expected ones, `--allow-unverified` never as the advice, only last with a warning.
 */
#[Test]
#[Covers(Advice::class)]
#[Covers(ConsoleSummary::class)]
final class AdviceTest
{
    /**
     * @return iterable<string, array{list<RejectionCause>, list<array{string, int, string}>}> Causes, [cause, count, text fragment].
     */
    public static function causes(): iterable
    {
        yield 'no runner' => [
            [RejectionCause::NotProvenNoRunner, RejectionCause::NotProvenNoRunner],
            [['not_proven.no_runner', 2, '`tests.runner`'], ['not_proven', 2, '`--allow-unverified`']],
        ];
        yield 'no coverage map' => [
            [RejectionCause::NotProvenNoCoverageMap],
            [['not_proven.no_coverage_map', 1, 'pcov or Xdebug in `php.binary`'], ['not_proven', 1, 'Warning']],
        ];
        yield 'harness first' => [
            [RejectionCause::NotProvenNoRunner, RejectionCause::NotProvenHarnessLoad],
            [
                ['not_proven.harness_load', 1, '`composer install`'],
                ['not_proven.no_runner', 1, '`tests.runner`'],
                ['not_proven', 2, '`--allow-unverified`'],
            ],
        ];
        yield 'side effects' => [
            [RejectionCause::NotProvenSideEffects],
            [['not_proven.side_effects', 1, 'only by the project\'s tests'], ['not_proven', 1, 'Last resort']],
        ];
        yield 'difference' => [[RejectionCause::DiffTest], [['diff_test', 1, '`.opmin/runs/1/counterexamples/`']]];
        yield 'public signature' => [[RejectionCause::SignaturePublicApi], [['signature.public_api', 1, '`--allow-public-signatures`']]];
        yield 'native types' => [[RejectionCause::SignatureNativeTypes], [['signature.native_types', 1, '`signatures.native_types']]];
        yield 'docblock' => [[RejectionCause::SignatureDocblock], [['signature.docblock', 1, '`signatures.phpdoc: true`']]];
        yield 'expected ones: a count only' => [
            [RejectionCause::NoGain, RejectionCause::Readability, RejectionCause::Ignored, RejectionCause::Review, RejectionCause::SignatureShape],
            [],
        ];
    }

    /**
     * @param list<RejectionCause> $causes
     * @param list<array{string, int, string}> $expected
     */
    #[DataProvider('causes')]
    public function advisesOnTheCauses(array $causes, array $expected): void
    {
        $rejected = \array_map(static fn(RejectionCause $c): array => ['kind' => $c->kind()->value, 'cause' => $c->value], $causes);

        $advice = Advice::of($rejected, '.opmin/runs/1/counterexamples/');

        Assert::same(\array_map(static fn(Advice $a): array => [$a->cause, $a->count], $advice), \array_map(static fn(array $e): array => [$e[0], $e[1]], $expected));
        foreach ($expected as $i => [, , $fragment]) {
            Assert::string($advice[$i]->text)->contains($fragment);
        }
    }

    public function roundTripsThroughTheReport(): void
    {
        $advice = Advice::of([['kind' => 'not_proven', 'cause' => 'not_proven.no_runner']], null);
        $report = ['advice' => [...\array_map(static fn(Advice $a): array => $a->toArray(), $advice), ['text' => 'broken']]];

        Assert::equals(Advice::fromReport($report), $advice);
        Assert::same(Advice::fromReport(['schema' => 1]), []);
    }

    public function consoleSummaryIsShort(): void
    {
        $functions = [];
        foreach (\range(1, 7) as $i) {
            $functions["App\\F::f{$i}"] = ['ops_before' => 10 + $i, 'ops_after' => 10, 'saved' => $i, 'status' => 'diff-tested'];
        }

        $causes = [RejectionCause::NotProvenHarnessLoad, RejectionCause::NotProvenNoRunner, RejectionCause::DiffTest, RejectionCause::SignatureDocblock, RejectionCause::NoGain];
        $rejected = \array_map(static fn(RejectionCause $c): array => ['kind' => $c->kind()->value, 'cause' => $c->value], $causes);
        $report = [
            'functions' => $functions,
            'rejected' => $rejected,
            'advice' => \array_map(static fn(Advice $a): array => $a->toArray(), Advice::of($rejected, null)),
        ];

        $lines = ConsoleSummary::lines($report);

        Assert::same($lines[0], 'Saved most (5 of 7 functions):');
        Assert::same($lines[1], '  App\F::f7  17 → 10  diff-tested');
        Assert::same($lines[6], 'Rolled back 5: 1 differential test found a difference, 2 behavior not proven, 1 signature rules, 1 no opcodes saved.');
        Assert::same($lines[7], 'What to do next (2 more in the report):');
        Assert::string($lines[8])->startsWith('  - The harness could not load');
        Assert::string($lines[9])->startsWith('  - Set `tests.runner`');
        Assert::string($lines[10])->startsWith('  ! Last resort: `--allow-unverified`');
        Assert::count($lines, 11);
    }

    public function oneFunctionNeedsNoTop(): void
    {
        $report = ['functions' => ['App\f' => ['ops_before' => 3, 'ops_after' => 2, 'saved' => 1, 'status' => 'tests']], 'rejected' => []];

        Assert::same(ConsoleSummary::lines($report), []);
    }
}
