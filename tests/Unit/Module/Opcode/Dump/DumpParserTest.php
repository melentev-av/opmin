<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Opcode\Dump;

use Opmin\Module\Opcode\Dump\DumpBlock;
use Opmin\Module\Opcode\Dump\DumpParseException;
use Opmin\Module\Opcode\Dump\DumpParser;
use Opmin\Module\Opcode\Dump\Phase;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

/**
 * The parser on real dumps of every PHP minor version of the matrix (tests/Fixtures/Dumps/).
 */
#[Test]
#[Covers(DumpParser::class)]
#[Covers(DumpBlock::class)]
final class DumpParserTest
{
    private const DUMPS = __DIR__ . '/../../../../Fixtures/Dumps';

    /**
     * Blocks of Basic.php per phase: 8.1 also dumps the two abstract methods.
     */
    public static function versions(): iterable
    {
        yield 'PHP 8.1' => ['8.1', 31];
        yield 'PHP 8.2' => ['8.2', 29];
        yield 'PHP 8.3' => ['8.3', 29];
        yield 'PHP 8.4' => ['8.4', 29];
        yield 'PHP 8.5' => ['8.5', 29];
    }

    #[DataProvider('versions')]
    public function parsesBothPhasesInTheSameOrder(string $version, int $blocks): void
    {
        $parsed = (new DumpParser())->parse(self::dump($version, 'Basic'));

        $raw = \array_values(\array_filter($parsed, static fn(DumpBlock $b): bool => $b->phase === Phase::Raw));
        $opt = \array_values(\array_filter($parsed, static fn(DumpBlock $b): bool => $b->phase === Phase::Opt));
        Assert::count($raw, $blocks);
        Assert::same(
            \array_map(static fn(DumpBlock $b): string => $b->name, $opt),
            \array_map(static fn(DumpBlock $b): string => $b->name, $raw),
        );
        Assert::same($opt[0]->name, '$_main');
        Assert::true($opt[0]->isMain());
    }

    #[DataProvider('versions')]
    public function readsStatsLocationAndHistogram(string $version): void
    {
        $top = self::block((new DumpParser())->parse(self::dump($version, 'Basic')), 'Fixture\Count\top', Phase::Opt);

        Assert::same($top->ops, 5);
        Assert::same([$top->args, $top->vars, $top->tmps], [1, 1, 1]);
        Assert::same([$top->file, $top->lineStart, $top->lineEnd], ['/fixtures/Basic.php', 109, 116]);
        Assert::same($top->opcodes, ['IS_SMALLER' => 1, 'JMPZ' => 1, 'RECV' => 1, 'RETURN' => 2]);
    }

    #[DataProvider('versions')]
    public function readsRawCountBeforeTheOptimizer(string $version): void
    {
        $top = self::block((new DumpParser())->parse(self::dump($version, 'Basic')), 'Fixture\Count\top', Phase::Raw);

        Assert::same($top->ops, 8);
    }

    /**
     * PHP 8.1–8.4 print string literals unescaped: they break opcode lines and imitate opcode lines,
     * `LIVE RANGES:` and a header (tests/Fixtures/Count/Strings.php).
     */
    #[DataProvider('versions')]
    public function stringLiteralsDoNotBreakOpcodeLines(string $version): void
    {
        $blocks = (new DumpParser())->parse(self::dump($version, 'Strings'));

        $imitation = self::block($blocks, 'Fixture\Count\imitation', Phase::Opt);
        Assert::same($imitation->opcodes, ['DO_ICALL' => 1, 'FAST_CONCAT' => 2, 'INIT_FCALL' => 1, 'RECV' => 1, 'RETURN' => 1, 'SEND_VAR' => 1]);
        Assert::same(self::block($blocks, 'Fixture\Count\after', Phase::Opt)->ops, 1);
        Assert::same(\array_column(\array_map(static fn(DumpBlock $b): array => ['n' => $b->name], $blocks), 'n'), [
            '$_main', 'Fixture\Count\newlines', 'Fixture\Count\imitation', 'Fixture\Count\after',
            '$_main', 'Fixture\Count\newlines', 'Fixture\Count\imitation', 'Fixture\Count\after',
        ]);
    }

    public function recognizesClosureNamesOfAllVersions(): void
    {
        $old = self::block((new DumpParser())->parse(self::dump('8.3', 'Basic')), 'Fixture\Count\{closure}', Phase::Opt);
        $new = (new DumpParser())->parse(self::dump('8.4', 'Basic'));

        Assert::true($old->isClosure());
        Assert::true(self::block($new, '{closure:Fixture\Count\Service::map():65}', Phase::Opt)->isClosure());
        Assert::false(self::block($new, 'Fixture\Count\Service::map', Phase::Opt)->isClosure());
    }

    public function cutsAnonymousClassNamesAtNulByte(): void
    {
        $dump = "class@anonymous\0/app/a.php:3\$0::m:\n     ; (lines=1, args=0, vars=0, tmps=0)\n"
            . "     ; (after optimizer)\n     ; /app/a.php:3-3\n0000 RETURN null\n";

        $blocks = (new DumpParser())->parse($dump);

        Assert::same($blocks[0]->name, 'class@anonymous');
    }

    public function readsPropertyHooks(): void
    {
        $blocks = (new DumpParser())->parse(self::dump('8.4', 'Hooks'));

        Assert::same(self::block($blocks, 'Fixture\Count\Person::$name::set', Phase::Opt)->ops, 5);
    }

    public function skipsSectionsAndWarningsThatAreNotBlocks(): void
    {
        $dump = "Warning: something:\nf:\n     ; (lines=2, args=0, vars=0, tmps=1)\n     ; (after optimizer)\n"
            . "     ; /a.php:1-1\n0000 T0 = QM_ASSIGN int(1)\n0001 RETURN T0\nLIVE RANGES:\n     0: 0000 - 0001 (tmp/var)\n"
            . "EXCEPTION TABLE:\n     0000, 0001, -, -\n";

        $blocks = (new DumpParser())->parse($dump);

        Assert::count($blocks, 1);
        Assert::same($blocks[0]->opcodes, ['QM_ASSIGN' => 1, 'RETURN' => 1]);
    }

    public function rejectsBlockWhoseOpcodesDoNotAddUp(): never
    {
        Expect::exception(DumpParseException::class)->withMessageContaining('header says lines=3, but 1 opcode lines follow');

        (new DumpParser())->parse("f:\n     ; (lines=3, args=0, vars=0, tmps=0)\n     ; (after optimizer)\n     ; /a.php:1-1\n0000 RETURN null\n");
    }

    public function headerWithoutPhaseOrLocationIsNotABlock(): void
    {
        # Such text comes from a string literal printed unescaped (PHP 8.1–8.3).
        $blocks = (new DumpParser())->parse("f:\n     ; (lines=1, args=0, vars=0, tmps=0)\n     ; /a.php:1-1\n0000 RETURN null\n");

        Assert::same($blocks, []);
    }

    private static function dump(string $version, string $fixture): string
    {
        return (string) \file_get_contents(self::DUMPS . "/{$version}/{$fixture}.txt");
    }

    /**
     * @param list<DumpBlock> $blocks
     */
    private static function block(array $blocks, string $name, Phase $phase): DumpBlock
    {
        foreach ($blocks as $block) {
            if ($block->name === $name && $block->phase === $phase) {
                return $block;
            }
        }

        throw new \LogicException("No block `{$name}` ({$phase->value}).");
    }
}
