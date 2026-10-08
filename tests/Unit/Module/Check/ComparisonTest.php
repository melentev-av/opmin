<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Check;

use Opmin\Module\Check\Baseline;
use Opmin\Module\Check\Comparison;
use Opmin\Module\Check\Finding;
use Opmin\Module\Check\FindingKind;
use Opmin\Module\Check\Suggestion;
use Opmin\Module\Opcode\FunctionCount;
use Opmin\Module\Opcode\Locate\UnitKind;
use Opmin\Module\Opcode\Report\CountReport;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

/**
 * The counts of the checked files against the baseline, function by function.
 */
#[Test]
#[Covers(Comparison::class)]
#[Covers(Finding::class)]
final class ComparisonTest
{
    #[DataSet([10, 11, 0, FindingKind::Grown], 'grew by one')]
    #[DataSet([10, 12, 2, null], 'grew within the tolerance')]
    #[DataSet([10, 13, 2, FindingKind::Grown], 'grew beyond the tolerance')]
    #[DataSet([10, 10, 0, null], 'same')]
    #[DataSet([10, 9, 0, FindingKind::Decreased], 'decreased')]
    public function comparesWithTheTolerance(int $before, int $after, int $tolerance, ?FindingKind $kind): void
    {
        $comparison = Comparison::compare(self::baseline(['App\f' => $before]), self::report(['App\f' => $after]), null, $tolerance, null);

        Assert::same(\array_map(static fn(Finding $f): FindingKind => $f->kind, $comparison->findings), $kind === null ? [] : [$kind]);
        Assert::same($comparison->failed(), $kind === FindingKind::Grown);
        Assert::same([$comparison->checked, $comparison->unchanged], [1, $kind === null ? 1 : 0]);
    }

    #[DataSet([null, FindingKind::New], 'no limit')]
    #[DataSet([20, FindingKind::New], 'within the limit')]
    #[DataSet([19, FindingKind::NewOverLimit], 'above the limit')]
    public function reportsNewFunctionsAgainstTheLimit(?int $limit, FindingKind $kind): void
    {
        $comparison = Comparison::compare(self::baseline([]), self::report(['App\new' => 20]), null, 0, $limit);

        Assert::same($comparison->findings[0]->kind, $kind);
        Assert::false($comparison->failed());
        Assert::same($comparison->updates, ['App\new' => ['ops' => 20, 'file' => 'src/a.php']]);
    }

    public function removedFunctionsAreLookedForOnlyInTheCheckedFiles(): void
    {
        $baseline = self::baseline(['App\kept' => 1, 'App\gone' => 2, 'App\other' => 3], ['App\other' => 'src/other.php']);
        $report = self::report(['App\kept' => 1]);

        $changed = Comparison::compare($baseline, $report, ['src/a.php'], 0, null);
        $all = Comparison::compare($baseline, $report, null, 0, null);

        Assert::same(\array_map(static fn(Finding $f): string => $f->key, $changed->of(FindingKind::Removed)), ['App\gone']);
        Assert::same(\array_map(static fn(Finding $f): string => $f->key, $all->of(FindingKind::Removed)), ['App\gone', 'App\other']);
        Assert::same($changed->updates, ['App\gone' => null]);
        Assert::same($changed->findings[0]->message(), 'App\gone: removed or renamed (had 2 opcodes)');
    }

    public function aMovedFunctionIsTheSameFunctionInItsNewFile(): void
    {
        $baseline = self::baseline(['App\f' => 5, 'App\g' => 5], ['App\f' => 'src/old.php', 'App\g' => 'src/old.php']);
        $report = self::report(['App\f' => 5, 'App\g' => 7], file: 'src/new.php');

        $comparison = Comparison::compare($baseline, $report, ['src/new.php', 'src/old.php'], 0, null);

        Assert::same(\array_map(static fn(Finding $f): string => $f->kind->value . ' ' . $f->key, $comparison->findings), ['grown App\g']);
        # The grown function keeps its count, only the file follows it.
        Assert::same($comparison->updates, [
            'App\f' => ['ops' => 5, 'file' => 'src/new.php'],
            'App\g' => ['ops' => 5, 'file' => 'src/new.php'],
        ]);
    }

    public function aFunctionDeclaredInTwoFilesIsMatchedByItsFile(): void
    {
        $baseline = self::baseline(['App\f' => 5, 'App\f#src/b.php' => 8], ['App\f#src/b.php' => 'src/b.php']);
        # Only b.php changed: counted alone, its function has no `#file` suffix.
        $report = self::report(['App\f' => 8], file: 'src/b.php');

        $comparison = Comparison::compare($baseline, $report, ['src/b.php'], 0, null);

        Assert::same([$comparison->findings, $comparison->unchanged, $comparison->updates], [[], 1, []]);
    }

    public function mainCodeIsNotGuarded(): void
    {
        $report = CountReport::create('1', '8.4.1', null, 'h', [
            new FunctionCount('src/a.php::<main>', UnitKind::Main, 'src/a.php', 1, 50, 50, 0, 0, 0, [], false),
        ]);

        $comparison = Comparison::compare(self::baseline([]), $report, null, 0, null);

        Assert::same([$comparison->findings, $comparison->checked], [[], 0]);
    }

    public function ordersErrorsFirstAndAddsSuggestionsToGrownFunctions(): void
    {
        $baseline = self::baseline(['App\a' => 5, 'App\b' => 5, 'App\c' => 5]);
        $report = self::report(['App\a' => 4, 'App\b' => 6, 'App\c' => 7, 'App\d' => 1]);

        $comparison = Comparison::compare($baseline, $report, null, 0, null)
            ->withSuggestions(static fn(Finding $f): Suggestion => new Suggestion(\stdClass::class, $f->delta()));

        Assert::same(\array_map(static fn(Finding $f): string => $f->key, $comparison->findings), ['App\b', 'App\c', 'App\a', 'App\d']);
        Assert::same($comparison->findings[1]->message(), "App\\c: opcodes grew 5 → 7 (+2); stdClass would save 2 (opmin optimize --rector-rule='stdClass')");
        Assert::null($comparison->findings[2]->suggestion);
        Assert::same($comparison->count(FindingKind::Grown), 2);
    }

    /**
     * @param array<non-empty-string, int<0, max>> $ops
     * @param array<non-empty-string, non-empty-string> $files
     */
    private static function baseline(array $ops, array $files = []): Baseline
    {
        $functions = [];
        foreach ($ops as $key => $count) {
            $functions[$key] = ['ops' => $count, 'file' => $files[$key] ?? 'src/a.php'];
        }

        return Baseline::create('8.4.1', '1', 'h', $functions);
    }

    /**
     * @param array<non-empty-string, int<0, max>> $ops
     * @param non-empty-string $file
     */
    private static function report(array $ops, string $file = 'src/a.php'): CountReport
    {
        $functions = [];
        $line = 10;
        foreach ($ops as $key => $count) {
            $functions[] = new FunctionCount($key, UnitKind::Function, $file, $line += 10, $count, $count, 0, 0, 0, [], true);
        }

        return CountReport::create('1', '8.4.1', null, 'h', $functions);
    }
}
