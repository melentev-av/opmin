<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Report;

use Opmin\Module\Optimize\LlmCandidate;
use Opmin\Module\Optimize\RunReport;
use Opmin\Module\Optimize\StepReport;
use Opmin\Module\Report\Environment;
use Opmin\Module\Report\MarkdownReport;
use Opmin\Rector\Rule\FullyQualifyGlobalCallsRector;
use Opmin\Rector\Rule\HoistLoopInvariantCountRector;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * `report.json` and `report.md` of a run built from its steps: the snapshot of both documents
 * (`tests/Fixtures/Report/`), the per-function aggregation, the grouping of rolled-back changes.
 *
 * To update the snapshots after an intended change: `OPMIN_UPDATE_SNAPSHOTS=1 composer test:unit`.
 */
#[Test]
#[Covers(RunReport::class)]
#[Covers(MarkdownReport::class)]
final class RunReportTest
{
    private const FIXTURES = __DIR__ . '/../../../Fixtures/Report';

    public function matchesTheSnapshots(): void
    {
        $data = self::report()->toArray();

        $json = \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";
        $markdown = MarkdownReport::render($data, 'opmin optimize: 20261007-120000');

        self::snapshot('report.json', $json);
        self::snapshot('report.md', $markdown);
    }

    public function aggregatesFunctionsAcrossSteps(): void
    {
        $data = self::report()->toArray();

        /** @var array<string, array<string, mixed>> $functions */
        $functions = $data['functions'];
        Assert::same(\array_keys($functions), ['App\Cart::sum', 'App\Cart::total']);
        $total = $functions['App\Cart::total'];
        Assert::same([$total['ops_before'], $total['ops_after'], $total['saved']], [20, 13, 7]);
        # The weakest proof and the lowest coverage of the steps.
        Assert::same([$total['status'], $total['diff_coverage'], $total['inputs']], ['tests', 80.0, 210]);
        # A check without inputs (the differential test did not run) adds its tests, not a 0% coverage.
        Assert::same($total['tests'], ['Tests\CartTest::total', 'Tests\CartTest::empty', 'Tests\CartTest::io']);
        Assert::same(\array_column($total['gains'], 'source'), ['rector', 'llm']);
        Assert::true($functions['App\Cart::sum']['executed_gain']);
        Assert::same($data['totals'], [
            'ops_before' => 40,
            'ops_after' => 33,
            'saved' => 7,
            'percent' => 17.5,
            'functions_changed' => 2,
            'changes_rolled_back' => 3,
        ]);
        Assert::same(\array_column($data['rejected'], 'kind'), ['no_gain', 'diff_test', 'readability']);
    }

    private static function report(): RunReport
    {
        $report = new RunReport(40, '/nonexistent/runs/20261007-120000');
        $report->environment = new Environment('0.2.0', '8.4.12', '8.3', 'a1b2c3d4e5f6', '2.7.0', '2.1.30', 'phpunit', 'vendor/bin/pint {files} (pint.json)');
        $report->warnings = ['php changed since the run 20261006-100000: 8.4.11 → 8.4.12 (opcode counts are not comparable).'];
        $report->opsAfter = 33;
        $report->finalTests = true;
        $report->patch = 'runs/20261007-120000/opmin.patch';
        $report->notes = ['Formatter: vendor/bin/pint {files} (pint.json)'];
        $report->functions = [
            'App\Cart::total' => ['file' => 'src/Cart.php', 'ops_after' => 13, 'flags' => ['io']],
            'App\Cart::sum' => ['file' => 'src/Cart.php', 'ops_after' => 12, 'flags' => []],
        ];

        $fqn = new StepReport(FullyQualifyGlobalCallsRector::class, 1);
        $fqn->commit = 'aaaaaaaa11111111';
        $fqn->accepted[] = ['file' => 'src/Cart.php', 'function' => 'App\Cart::total', 'gain' => 4, 'status' => 'diff-tested', 'checks' => [
            ['key' => 'App\Cart::total', 'status' => 'diff-tested', 'coverage' => 100.0, 'inputs' => 210, 'tests' => ['Tests\CartTest::total']],
        ]];
        $fqn->reject('src/Cart.php', 'App\Cart::name', 'saves no opcodes');
        $fqn->reject('src/Cart.php', 'App\Cart::price', 'differential test: return differs: 1.5 vs 1', [
            'test' => 'runs/20261007-120000/counterexamples/CartPriceTest.php',
            'input' => ['args' => [['kind' => 'string', 'value' => '1.5']]],
        ]);
        $report->steps[] = $fqn;

        $hoist = new StepReport(HoistLoopInvariantCountRector::class, 1);
        $hoist->executedGain = true;
        $hoist->commit = 'bbbbbbbb22222222';
        $hoist->accepted[] = ['file' => 'src/Cart.php', 'function' => 'App\Cart::sum', 'gain' => 0, 'status' => 'diff-tested', 'checks' => [
            ['key' => 'App\Cart::sum', 'status' => 'diff-tested', 'coverage' => 92.5, 'inputs' => 180, 'tests' => [], 'time_change_percent' => -12.5],
        ]];
        $report->steps[] = $hoist;

        $llm = new StepReport(LlmCandidate::class, 1);
        $llm->commit = 'cccccccc33333333';
        $llm->accepted[] = ['file' => 'src/Cart.php', 'function' => 'App\Cart::total', 'gain' => 3, 'status' => 'tests', 'checks' => [
            ['key' => 'App\Cart::total', 'status' => 'tests', 'coverage' => 80.0, 'inputs' => 150, 'tests' => ['Tests\CartTest::total', 'Tests\CartTest::empty']],
            ['key' => 'App\Cart::total::{closure#1}', 'status' => 'diff-tested', 'coverage' => null, 'inputs' => null, 'tests' => []],
            ['key' => 'App\Cart::total::{closure#2}', 'status' => 'tests', 'coverage' => 0.0, 'inputs' => 0, 'tests' => ['Tests\CartTest::io']],
        ]];
        $llm->reject('src/Cart.php', 'App\Cart::total', 'cyclomatic complexity grew by 1 (more branches; readability.max_cyclomatic_increase is 0)');
        $report->steps[] = $llm;

        return $report;
    }

    private static function snapshot(string $name, string $actual): void
    {
        $file = self::FIXTURES . '/' . $name;
        if (\getenv('OPMIN_UPDATE_SNAPSHOTS') === '1') {
            @\mkdir(self::FIXTURES, 0777, true);
            \file_put_contents($file, $actual);
        }

        Assert::same($actual, (string) \file_get_contents($file));
    }
}
