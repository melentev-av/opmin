<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Tests;

use Opmin\Module\Lint\PhpStanResult;
use Opmin\Module\Lint\PhpStanRunner;
use Opmin\Module\Tests\CommandLine;
use Opmin\Module\Tests\CoverageMap;
use Opmin\Module\Tests\CoverageXmlReport;
use Opmin\Module\Tests\JunitReport;
use Opmin\Module\Tests\PhpUnitAdapter;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(JunitReport::class)]
#[Covers(CoverageXmlReport::class)]
#[Covers(CoverageMap::class)]
#[Covers(CommandLine::class)]
#[Covers(PhpStanResult::class)]
#[Covers(PhpStanRunner::class)]
final class ReportsTest
{
    private const FIXTURES = __DIR__ . '/../../../Fixtures/Tests';

    public function readsFailedTestsFromJunit(): void
    {
        $report = JunitReport::read(self::FIXTURES . '/junit.xml');

        Assert::same($report['tests'] ?? null, 3);
        Assert::same($report['failed'] ?? null, ['Tests\MathTest::testSubtracts', 'Tests\MathTest::testDivides with data set #0']);
        Assert::null(JunitReport::read(self::FIXTURES . '/missing.xml'));
    }

    public function readsTestsPerLineFromCoverageXml(): void
    {
        $map = CoverageXmlReport::read(self::FIXTURES . '/coverage-xml');

        Assert::notNull($map);
        Assert::same($map->tests('/p/src/Math.php', 4, 6), ['Tests\MathTest::testAdds', 'Tests\MathTest::testSubtracts']);
        Assert::same($map->tests('/p/src/Math.php', 7, 20), ['Tests\MathTest::testDivides with data set #0']);
        Assert::same($map->tests('/p/src/Other.php', 1, 100), []);
    }

    public function filterMatchesExactlyTheTestsWithTheirDataSets(): void
    {
        $filter = PhpUnitAdapter::filter(['Tests\MathTest::testAdds', 'Tests\MathTest::testDivides with data set #0']);

        Assert::same(\preg_match($filter, 'Tests\MathTest::testAdds'), 1);
        Assert::same(\preg_match($filter, 'Tests\MathTest::testDivides with data set "big"'), 1);
        Assert::same(\preg_match($filter, 'Tests\MathTest::testAddsMore'), 0);
        Assert::same(\preg_match($filter, 'Other\MathTest::testAdds'), 0);
    }

    public function splitsCommandsLikeAShell(): void
    {
        Assert::same(CommandLine::split('vendor/bin/phpstan analyse  --memory-limit="1 G" \'a b\''), ['vendor/bin/phpstan', 'analyse', '--memory-limit=1 G', 'a b']);
        Assert::same(CommandLine::split(''), []);
        Assert::same(CommandLine::split('run ""'), ['run', '']);
    }

    public function newErrorsIgnoreLinesAndCountRepeats(): void
    {
        $error = static fn(string $message, int $line): array => ['file' => '/p/a.php', 'message' => $message, 'identifier' => 'x', 'line' => $line];
        $before = new PhpStanResult([$error('one', 3), $error('two', 5)]);
        $after = new PhpStanResult([$error('one', 10), $error('two', 12), $error('two', 14), $error('three', 1)]);

        Assert::same(\array_column(PhpStanResult::newErrors($before, $after), 'message'), ['two', 'three']);
        Assert::same(PhpStanResult::newErrors($after, $before), []);
    }

    public function parsesPhpStanJson(): void
    {
        $result = PhpStanRunner::parse('{"totals":{"errors":1,"file_errors":1},"files":{"/p/a.php":{"errors":1,"messages":[{"message":"Undefined variable: $x","line":3,"ignorable":true,"identifier":"variable.undefined"}]}},"errors":["Config is broken"]}');

        Assert::true($result->ran);
        Assert::same($result->errors[0], ['file' => '/p/a.php', 'message' => 'Undefined variable: $x', 'identifier' => 'variable.undefined', 'line' => 3]);
        Assert::same($result->errors[1]['message'], 'Config is broken');
        Assert::false(PhpStanRunner::parse('Fatal error')->ran);
    }
}
