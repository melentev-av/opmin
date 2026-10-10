<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Tests;

use Opmin\Module\Lint\PhpStanResult;
use Opmin\Module\Lint\PhpStanRunner;
use Opmin\Module\Tests\CommandLine;
use Opmin\Module\Tests\CoverageMap;
use Opmin\Module\Tests\CoverageXmlReport;
use Opmin\Module\Tests\JunitReport;
use Opmin\Module\Tests\PestAdapter;
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
#[Covers(PestAdapter::class)]
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

    public function readsJunitWithoutDomEscapesAndATruncatedFile(): void
    {
        $dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-junit-' . \bin2hex(\random_bytes(4));
        \mkdir($dir);
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="A &gt; B" tests="3">
                <testcase name="testCompares with data set &quot;a &gt; b&quot;" class="Tests\CmpTest" time="0.5">
                  <failure type="X">expected &lt;error&gt; tag</failure>
                </testcase>
                <testcase name='testQuoted' class='Tests\CmpTest' time='0.25'/>
                <testcase name="testOut" class="Tests\CmpTest" time="0.25"><system-out>&lt;failure&gt;</system-out></testcase>
              </testsuite>
            </testsuites>
            XML;
        \file_put_contents("{$dir}/ok.xml", $xml);
        \file_put_contents("{$dir}/cut.xml", \substr($xml, 0, -20));

        $report = JunitReport::read("{$dir}/ok.xml");
        $cut = JunitReport::read("{$dir}/cut.xml");
        \exec('rm -rf ' . \escapeshellarg($dir));

        Assert::same($report, ['tests' => 3, 'failed' => ['Tests\CmpTest::testCompares with data set "a > b"'], 'seconds' => 1.0]);
        Assert::null($cut);
    }

    public function theOrchestratorReadsXmlWithoutExtensionsOfLibxml(): void
    {
        # The static binary has no libxml (see SPC extensions in .github/actions/binary): a class of ext-dom
        # or ext-xml there is a fatal error only the binary shows.
        $used = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../../../src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $code = (string) \file_get_contents((string) $file);
            \preg_match_all('/\\\\?\b(DOM[A-Z]\w+|SimpleXML\w*|simplexml_\w+|XMLReader|XMLWriter|xml_parser_create\w*|libxml_\w+)\b/', $code, $m);
            foreach ($m[1] as $name) {
                $used[] = \basename((string) $file) . ': ' . $name;
            }
        }

        Assert::same($used, []);
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
        $filter = PhpUnitAdapter::filter(['Tests\MathTest::testAdds', 'Tests\MathTest::testDivides with data set #0', 'Tests\MathTest::testRounds#half up']);

        Assert::same(\preg_match($filter, 'Tests\MathTest::testAdds'), 1);
        Assert::same(\preg_match($filter, 'Tests\MathTest::testAdds with data set #3'), 1);
        Assert::same(\preg_match($filter, 'Tests\MathTest::testDivides with data set #0'), 1);
        Assert::same(\preg_match($filter, 'Tests\MathTest::testDivides with data set #1'), 0);
        Assert::same(\preg_match($filter, 'Tests\MathTest::testRounds with data set "half up"'), 1);
        Assert::same(\preg_match($filter, 'Tests\MathTest::testRounds with data set "half down"'), 0);
        Assert::same(\preg_match($filter, 'Tests\MathTest::testAddsMore'), 0);
        Assert::same(\preg_match($filter, 'Other\MathTest::testAdds'), 0);
    }

    public function aFilterOfThousandsOfTestsFallsBackToTheirClassesThenToAll(): void
    {
        $few = ['Tests\MathTest::testAdds'];
        $manyMethods = [];
        for ($i = 0; $i < 3000; ++$i) {
            $manyMethods[] = 'Tests\Math' . ($i % 3) . 'Test::testCase' . $i . ' with data set #' . $i;
        }
        $manyClasses = [];
        for ($i = 0; $i < 3000; ++$i) {
            $manyClasses[] = 'Tests\Generated\Class' . $i . 'WithALongEnoughNameTest::testOne';
        }

        $byClass = (string) PhpUnitAdapter::boundedFilter($manyMethods);
        $pest = (string) PestAdapter::boundedFilter(\array_map(static fn(string $id): string => 'P\\' . $id, $manyMethods));

        Assert::same(PhpUnitAdapter::boundedFilter($few), PhpUnitAdapter::filter($few));
        Assert::true(\strlen(PhpUnitAdapter::filter($manyMethods)) > PhpUnitAdapter::MAX_FILTER);
        Assert::same(\preg_match($byClass, 'Tests\Math2Test::testAnything'), 1);
        Assert::same(\preg_match($byClass, 'Tests\Other::testCase1'), 0);
        Assert::same(\preg_match($pest, 'Tests\Math1Test::it works'), 1);
        Assert::null(PhpUnitAdapter::boundedFilter($manyClasses));
    }

    public function pestFilterRestoresDescriptionsFromEvaluableNames(): void
    {
        $filter = PestAdapter::filter([
            'P\Tests\ObviousTest::__pest_evaluable_sum_of_even_numbers',
            'P\Tests\ObviousTest::__pest_evaluable_describe#dataset "array"',
            'P\Tests\Feature\ApiTest::__pest_evaluable_it_returns_a_snake__case_key',
            'Tests\Unit\PlainTest::testPlain',
        ]);

        Assert::same(\preg_match($filter, 'Tests\ObviousTest::sum of even numbers'), 1);
        Assert::same(\preg_match($filter, 'Tests\ObviousTest::sum of odd numbers'), 0);
        Assert::same(\preg_match($filter, 'Tests\ObviousTest::describe with data set "dataset "array""'), 1);
        Assert::same(\preg_match($filter, 'Tests\ObviousTest::describe with data set "dataset "string""'), 0);
        Assert::same(\preg_match($filter, 'Tests\Feature\ApiTest::it returns a snake_case key'), 1);
        Assert::same(\preg_match($filter, 'Tests\Unit\PlainTest::testPlain'), 1);
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
