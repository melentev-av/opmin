<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Check;

use Opmin\Module\Check\Baseline;
use Opmin\Module\Check\Comparison;
use Opmin\Module\Check\Finding;
use Opmin\Module\Check\Format;
use Opmin\Module\Check\Renderer;
use Opmin\Module\Check\Suggestion;
use Opmin\Module\Opcode\FunctionCount;
use Opmin\Module\Opcode\Locate\UnitKind;
use Opmin\Module\Opcode\Report\CountReport;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The outputs of `opmin check`: snapshots of every format and the validity of the documents.
 *
 * To update the snapshots after an intended change: `OPMIN_UPDATE_SNAPSHOTS=1 composer test:unit`.
 */
#[Test]
#[Covers(Renderer::class)]
final class RendererTest
{
    private const FIXTURES = __DIR__ . '/../../../Fixtures/Check';

    public static function formats(): iterable
    {
        yield 'json' => [Format::Json, 'check.json'];
        yield 'github' => [Format::Github, 'check.github.txt'];
        yield 'gitlab' => [Format::Gitlab, 'check.gitlab.json'];
        yield 'checkstyle' => [Format::Checkstyle, 'check.checkstyle.xml'];
    }

    #[DataProvider('formats')]
    public function matchesTheSnapshot(Format $format, string $file): void
    {
        self::snapshot($file, self::renderer()->render($format));
    }

    public function githubEscapesPropertiesAndData(): void
    {
        $lines = \explode("\n", \trim(self::renderer()->github()));

        Assert::same(\count($lines), 3);
        # `,` and `:` would end a property, `%` starts an escape, a newline ends the command.
        Assert::string($lines[1])->contains('::warning file=src/odd%2C "name" & co.php,line=12,title=opmin%3A new_over_limit::');
        Assert::string($lines[1])->contains('App\Odd::{closure:1}: new function with 30 opcodes');
        Assert::string($lines[0])->contains('opcodes grew 5 → 9 (+4, tolerance 1); FullyQualifyGlobalCallsRector would save 4');
    }

    public function gitlabIsACodeQualityReport(): void
    {
        /** @var list<array<string, mixed>> $issues */
        $issues = \json_decode(self::renderer()->gitlab(), true, flags: \JSON_THROW_ON_ERROR);

        Assert::same(\count($issues), 3);
        foreach ($issues as $issue) {
            Assert::array($issue)->hasKeys('description', 'check_name', 'fingerprint', 'severity', 'location');
            Assert::true(\in_array($issue['severity'], ['info', 'minor', 'major', 'critical', 'blocker'], true));
            /** @var array{path: string, lines: array{begin: int}} $location */
            $location = $issue['location'];
            Assert::true($location['lines']['begin'] > 0);
        }

        Assert::same(\count(\array_unique(\array_column($issues, 'fingerprint'))), 3);
    }

    public function checkstyleIsValidXml(): void
    {
        $xml = new \DOMDocument();

        Assert::true($xml->loadXML(self::renderer()->checkstyle()));
        Assert::same($xml->documentElement?->nodeName, 'checkstyle');
        Assert::same($xml->getElementsByTagName('file')->length, 2);
        Assert::same($xml->getElementsByTagName('error')->length, 3);
        Assert::same($xml->getElementsByTagName('error')->item(0)?->getAttribute('severity'), 'error');
        Assert::same($xml->getElementsByTagName('file')->item(1)?->getAttribute('name'), 'src/odd, "name" & co.php');
    }

    public function nothingToAnnotateGivesEmptyDocuments(): void
    {
        $comparison = Comparison::compare(Baseline::create('8.4.1', '1', 'h', []), CountReport::create('1', '8.4.1', null, 'h', []), [], 0, null);
        $renderer = new Renderer($comparison, 0, null, 'origin/main', '8.4.1');

        Assert::same($renderer->github(), '');
        Assert::same($renderer->gitlab(), "[]\n");
        Assert::same($renderer->checkstyle(), "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<checkstyle version=\"4.3\">\n</checkstyle>\n");
        Assert::string($renderer->json())->contains('"status": "passed"');
    }

    /**
     * A grown function with a suggestion, a new one above the limit in a file with an awkward name,
     * a decreased one, a new one within the limit and a removed one.
     */
    private static function renderer(): Renderer
    {
        $baseline = Baseline::create('8.4.1', '1.0.0', 'h', [
            'App\Price::calc' => ['ops' => 5, 'file' => 'src/Price.php'],
            'App\Price::total' => ['ops' => 9, 'file' => 'src/Price.php'],
            'App\Price::gone' => ['ops' => 3, 'file' => 'src/Price.php'],
        ]);
        $count = static fn(string $key, int $ops, string $file, int $line): FunctionCount => new FunctionCount(
            $key,
            UnitKind::Method,
            $file,
            $line,
            $ops,
            $ops,
            0,
            0,
            0,
            [],
            true,
        );
        $report = CountReport::create('1.0.0', '8.4.1', null, 'h', [
            $count('App\Price::calc', 9, 'src/Price.php', 20),
            $count('App\Price::total', 7, 'src/Price.php', 30),
            $count('App\Price::<tax & "fee">', 4, 'src/Price.php', 40),
            $count('App\Odd::{closure:1}', 30, 'src/odd, "name" & co.php', 12),
            $count('App\Odd::small', 2, 'src/odd, "name" & co.php', 20),
        ]);
        $comparison = Comparison::compare($baseline, $report, ['src/Price.php', 'src/odd, "name" & co.php'], 1, 25)
            ->withSuggestions(static fn(Finding $f): Suggestion => new Suggestion('Opmin\Rector\Rule\FullyQualifyGlobalCallsRector', 4));

        return new Renderer($comparison, 1, 25, 'origin/main', '8.4.1');
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
