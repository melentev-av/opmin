<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Opcode\Locate;

use Opmin\Module\Opcode\Dump\DumpParser;
use Opmin\Module\Opcode\FunctionCount;
use Opmin\Module\Opcode\Locate\DumpMatcher;
use Opmin\Module\Opcode\Locate\FunctionLocator;
use Opmin\Module\Opcode\Locate\MatchException;
use Opmin\Module\Opcode\Locate\UnitKind;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

/**
 * Real dumps of every PHP minor version against the hand-checked counts of tests/Fixtures/Count/expected.php.
 */
#[Test]
#[Covers(DumpMatcher::class)]
final class DumpMatcherTest
{
    private const FIXTURES = __DIR__ . '/../../../../Fixtures';

    public static function fixtures(): iterable
    {
        /** @var array<string, array<string, array<string, int>>> $expected */
        $expected = require self::FIXTURES . '/Count/expected.php';
        foreach ($expected as $version => $files) {
            foreach ($files as $file => $counts) {
                yield "PHP {$version} {$file}" => [$version, $file, $counts];
            }
        }
    }

    /**
     * @param array<string, int> $expected
     */
    #[DataProvider('fixtures')]
    public function attributesOpcodesToFunctions(string $version, string $file, array $expected): void
    {
        $counts = self::match($version, $file);

        $actual = [];
        foreach ($counts as $count) {
            $actual[\str_replace("{$file}::", '', $count->key)] = $count->opsOpt;
        }
        \ksort($actual);
        \ksort($expected);
        Assert::same($actual, $expected);
    }

    public function keepsRawCountsLinesAndMainFlag(): void
    {
        $counts = self::byKey(self::match('8.5', 'Basic.php'));

        $map = $counts['Fixture\Count\Service::map'];
        Assert::same([$map->opsRaw, $map->opsOpt, $map->line, $map->kind], [20, 14, 62, UnitKind::Method]);
        Assert::true($map->optimizable);
        Assert::false($counts['Basic.php::<main>']->optimizable);
        Assert::same($counts['Basic.php::<main>']->line, 1);
    }

    public function rejectsDumpOfAnotherFile(): never
    {
        Expect::exception(MatchException::class)->withMessageContaining('does not match');

        $units = (new FunctionLocator())->locate("<?php\nfunction other() {}\n", 'x.php::<main>');
        (new DumpMatcher())->match($units, self::blocks('8.5', 'Readonly'), 'x.php');
    }

    public function rejectsDumpWithExtraBlocks(): never
    {
        Expect::exception(MatchException::class)->withMessageContaining('has no matching function in the source');

        $units = (new FunctionLocator())->locate("<?php\n", 'x.php::<main>');
        (new DumpMatcher())->match($units, self::blocks('8.5', 'Readonly'), 'x.php');
    }

    public function rejectsDumpWithMissingBlocks(): never
    {
        Expect::exception(MatchException::class)->withMessageContaining('The dump ended');

        $code = (string) \file_get_contents(self::FIXTURES . '/Count/Readonly.php') . "\nclass Extra { function e() {} }\n";
        $units = (new FunctionLocator())->locate($code, 'x.php::<main>');
        (new DumpMatcher())->match($units, self::blocks('8.5', 'Readonly'), 'x.php');
    }

    public function rejectsClosuresSwappedByLine(): never
    {
        Expect::exception(MatchException::class)->withMessageContaining('does not match');

        # Same structure as the dump, but the closure ends on another line: never attributed by position alone.
        $code = \str_replace("    public function twoOnOneLine(): array { return [fn() => 1, fn() => 2]; }", "    public function twoOnOneLine(): array { return [fn() => 1,\n fn() => 2]; }", (string) \file_get_contents(self::FIXTURES . '/Count/Basic.php'));
        $units = (new FunctionLocator())->locate($code, 'x.php::<main>');
        (new DumpMatcher())->match($units, self::blocks('8.5', 'Basic'), 'x.php');
    }

    public function refusesToGuessWhichClosureOnALineWasDropped(): never
    {
        Expect::exception(MatchException::class)->withMessageContaining('Cannot tell which closure or anonymous class on line 13');

        # The dropped closure moves to the line of the compiled one: the block cannot be told apart.
        $code = \str_replace(
            ", false && (fn(): int => 1),\n        fn(): int => \$a];",
            ",\n        false && (fn(): int => 1), fn(): int => \$a];",
            (string) \file_get_contents(self::FIXTURES . '/Count/Dead.php'),
        );
        $units = (new FunctionLocator())->locate($code, 'x.php::<main>');
        (new DumpMatcher())->match($units, self::blocks('8.5', 'Dead'), 'x.php');
    }

    /**
     * @return list<FunctionCount>
     */
    private static function match(string $version, string $file): array
    {
        $units = (new FunctionLocator())->locate((string) \file_get_contents(self::FIXTURES . "/Count/{$file}"), "{$file}::<main>");

        return (new DumpMatcher())->match($units, self::blocks($version, \basename($file, '.php')), $file);
    }

    /**
     * @return list<\Opmin\Module\Opcode\Dump\DumpBlock>
     */
    private static function blocks(string $version, string $name): array
    {
        return (new DumpParser())->parse((string) \file_get_contents(self::FIXTURES . "/Dumps/{$version}/{$name}.txt"));
    }

    /**
     * @param list<FunctionCount> $counts
     * @return array<string, FunctionCount>
     */
    private static function byKey(array $counts): array
    {
        $result = [];
        foreach ($counts as $count) {
            $result[$count->key] = $count;
        }

        return $result;
    }
}
