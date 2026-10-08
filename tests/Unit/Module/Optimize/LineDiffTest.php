<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Optimize;

use Opmin\Module\Optimize\LineDiff;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(LineDiff::class)]
final class LineDiffTest
{
    /**
     * @return array<string, \Rasuvaeff\PropertyTesting\ArbitraryInterface>
     */
    public static function patchReproducesBothTextsGenerators(): array
    {
        $lines = Gen::arrayOf(Gen::elements(["a", "b", "c", ""]), 0, 12);

        return ['old' => $lines, 'new' => $lines];
    }

    /**
     * @param list<string> $old
     * @param list<string> $new
     */
    #[Property(runs: 300)]
    public function patchReproducesBothTexts(array $old, array $new): void
    {
        # Every line ends with a newline: a text of N lines.
        $text = static fn(array $lines): string => $lines === [] ? '' : \implode("\n", $lines) . "\n";
        $diff = (new LineDiff($text($old), $text($new)))->unified(1000);

        $minus = $plus = [];
        foreach (\explode("\n", $diff) as $line) {
            if ($line === '' || \str_starts_with($line, '@@')) {
                continue;
            }

            $line[0] === '+' or $minus[] = \substr($line, 1);
            $line[0] === '-' or $plus[] = \substr($line, 1);
        }

        if ($diff !== '') {
            Assert::same($minus, $old);
            Assert::same($plus, $new);
        } else {
            Assert::same($old, $new);
        }
    }

    #[DataSet(["a\nb\nc\n", "a\nb\nc\n", 0], 'same')]
    #[DataSet(["a\nb\nc\n", "a\nB\nc\n", 1], 'one modified line')]
    #[DataSet(["a\nb\n", "a\nx\ny\nb\n", 2], 'two inserted lines')]
    #[DataSet(["a\nb\nc\nd\n", "a\nd\n", 2], 'two deleted lines')]
    #[DataSet(["return \$this->a + \$this->a;\n", "\$a = \$this->a;\nreturn \$a + \$a;\n", 2], 'extracted variable')]
    public function changedLinesCountAModifiedLineOnce(string $old, string $new, int $changed): void
    {
        Assert::same((new LineDiff($old, $new))->changedLines(), $changed);
    }

    public function unifiedHunksHaveContext(): void
    {
        $diff = (new LineDiff("1\n2\n3\n4\n5\n6\n7\n8\n9\n", "1\n2\n3\nfour\n5\n6\n7\n8\n9\n"))->unified(1);

        Assert::same($diff, "@@ -3,3 +3,3 @@\n 3\n-4\n+four\n 5\n");
    }

    public function insertionIntoAnEmptyTextStartsAtZero(): void
    {
        Assert::same((new LineDiff('', "a\n"))->unified(), "@@ -0,0 +1,1 @@\n+a\n");
    }
}
