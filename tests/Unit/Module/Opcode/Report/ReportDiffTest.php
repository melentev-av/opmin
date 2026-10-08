<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Opcode\Report;

use Opmin\Module\Opcode\FunctionCount;
use Opmin\Module\Opcode\Locate\UnitKind;
use Opmin\Module\Opcode\Report\CountReport;
use Opmin\Module\Opcode\Report\ReportDiff;
use Opmin\Module\Opcode\Report\ReportException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ReportDiff::class)]
#[Covers(CountReport::class)]
final class ReportDiffTest
{
    public function sortsGainsAndLossesBySize(): void
    {
        $before = self::report(['a' => 10, 'b' => 10, 'c' => 10, 'd' => 5, 'gone' => 3]);
        $after = self::report(['a' => 9, 'b' => 4, 'c' => 12, 'd' => 5, 'new' => 7]);

        $diff = ReportDiff::compare($before, $after);

        Assert::same(\array_column($diff->better, 'key'), ['b', 'a']);
        Assert::same($diff->better[0], ['key' => 'b', 'before' => 10, 'after' => 4, 'delta' => -6]);
        Assert::same(\array_column($diff->worse, 'delta'), [2]);
        Assert::same([$diff->added, $diff->removed, $diff->unchanged], [['new' => 7], ['gone' => 3], 1]);
        Assert::same([$diff->totalBefore, $diff->totalAfter], [38, 37]);
    }

    public function refusesReportsOfDifferentPhpVersions(): never
    {
        Expect::exception(ReportException::class)->withMessageContaining('different PHP versions (8.4.1 and 8.4.2)');

        ReportDiff::compare(self::report([], php: '8.4.1'), self::report([], php: '8.4.2'));
    }

    public function refusesReportsOfDifferentOptimizerSettings(): never
    {
        Expect::exception(ReportException::class)->withMessageContaining('different optimizer settings');

        ReportDiff::compare(self::report([], hash: 'aaa'), self::report([], hash: 'bbb'));
    }

    public function warnsAboutAnotherOpminVersionOrTarget(): void
    {
        $before = CountReport::create('1.0.0', '8.4.1', '8.2', 'h', [self::count('App\f', 3, 'a.php')]);
        $after = CountReport::create('1.1.0', '8.4.1', '8.3', 'h', [self::count('App\f', 2, 'a.php')]);

        $diff = ReportDiff::compare($before, $after);

        Assert::same($diff->warnings, [
            'The reports were taken with different opmin versions (1.0.0 and 1.1.0).',
            'The reports were taken with different php.target (8.2 and 8.3).',
        ]);
        Assert::same(ReportDiff::compare($before, $before)->warnings, []);
    }

    public function reportRoundTripsThroughJson(): void
    {
        $report = self::report(['b' => 2, 'a' => 1], errors: ['z.php' => 'ParseError']);

        $json = $report->toJson();
        $restored = CountReport::fromJson($json, 'r.json');

        Assert::same($restored->toJson(), $json);
        Assert::same(\array_keys($restored->functions), ['a', 'b']);
        Assert::string($json)->contains('"totals": {');
    }

    public function emptyReportKeepsObjectsInJson(): void
    {
        $json = self::report([])->toJson();

        Assert::string($json)->contains('"functions": {}');
        Assert::string($json)->contains('"errors": {}');
    }

    public function sameFunctionInTwoFilesGetsFileSuffix(): void
    {
        $report = CountReport::create('1', '8.4.1', null, 'h', [self::count('App\f', 1, 'a.php'), self::count('App\f', 2, 'b.php')]);

        Assert::same(\array_keys($report->functions), ['App\f', 'App\f#b.php']);
    }

    public function rejectsForeignJson(): never
    {
        Expect::exception(ReportException::class)->withMessageContaining('`x.json` is not an opmin count report');

        CountReport::fromJson('{"foo": 1}', 'x.json');
    }

    /**
     * @param array<non-empty-string, int<0, max>> $ops
     * @param array<non-empty-string, non-empty-string> $errors
     */
    private static function report(array $ops, string $php = '8.4.1', string $hash = 'h', array $errors = []): CountReport
    {
        $functions = [];
        foreach ($ops as $key => $n) {
            $functions[] = self::count($key, $n, 'a.php');
        }

        return CountReport::create('1.0.0', $php, '8.3', $hash, $functions, $errors);
    }

    /**
     * @param non-empty-string $key
     * @param int<0, max> $ops
     * @param non-empty-string $file
     */
    private static function count(string $key, int $ops, string $file): FunctionCount
    {
        return new FunctionCount($key, UnitKind::Function, $file, 1, $ops + 1, $ops, 0, 0, 0, $ops > 0 ? ['RETURN' => $ops] : [], true);
    }
}
