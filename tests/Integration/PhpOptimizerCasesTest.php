<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration;

use Opmin\Module\Opcode\Dump\DumpBlock;
use Opmin\Module\Opcode\Dump\DumpParser;
use Opmin\Module\Opcode\Dump\FileDump;
use Opmin\Module\Opcode\Dump\OpcacheDumper;
use Opmin\Module\Opcode\Dump\Phase;
use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * What PHP itself does with the code opmin's rules write (`tests/Cases/`): for each case, the
 * opcodes of `f()` that disappear and appear from "before" to "after" after the optimizer, and the
 * sign of the change of the count, on the PHP of `php.binary` (the CI runs it with 8.1–8.5).
 *
 * A case states the PHP facts a rule relies on (`sprintf()` is a rope from 8.4, `match (true)` compares
 * with `true`…). A new PHP version without an expectation fails here: run the case on it and see
 * which facts, and so which rules, changed. The cost of the rules is measured by `bin/bench --cost`.
 *
 * `OPMIN_CASES_OBSERVE=1` prints what the current PHP does instead of checking it.
 */
#[Test]
final class PhpOptimizerCasesTest
{
    private const CASES = __DIR__ . '/../Cases';

    private string $dir = '';

    /**
     * @return iterable<string, array{string}>
     */
    public static function cases(): iterable
    {
        foreach (\glob(self::CASES . '/*.php') ?: [] as $file) {
            yield \basename($file, '.php') => [$file];
        }
    }

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = \sys_get_temp_dir() . '/opmin-cases-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir);
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    #[DataProvider('cases')]
    public function phpDoesWhatTheCaseSays(string $file): void
    {
        /** @var array{rule: class-string|null, before: string, after: string, expect: array<string, array{gone?: list<string>, new?: list<string>, ops: '-'|'0'|'+'}>} $case */
        $case = require $file;
        $before = $this->opcodes($case['before'], 'before');
        $after = $this->opcodes($case['after'], 'after');
        $observed = [
            'gone' => \array_values(\array_diff(\array_keys($before->opcodes), \array_keys($after->opcodes))),
            'new' => \array_values(\array_diff(\array_keys($after->opcodes), \array_keys($before->opcodes))),
            'ops' => match ($after->ops <=> $before->ops) {
                -1 => '-', 0 => '0', 1 => '+',
            },
        ];
        $minor = TestPhp::minor();
        if (\getenv('OPMIN_CASES_OBSERVE') === '1') {
            \fwrite(\STDERR, \sprintf("%s %s %s %d→%d\n", $minor, \basename($file, '.php'), \json_encode($observed), $before->ops, $after->ops));
            Assert::true(true);

            return;
        }

        $expected = self::expectation($case['expect'], $minor);
        Assert::notNull($expected, \sprintf(
            'No expectation for PHP %s: run with OPMIN_CASES_OBSERVE=1 and see what changed (%s).',
            $minor,
            \json_encode($observed),
        ));
        /** @var array{gone?: list<string>, new?: list<string>, ops: '-'|'0'|'+'} $expected */
        Assert::same(
            ['gone' => $expected['gone'] ?? [], 'new' => $expected['new'] ?? [], 'ops' => $expected['ops']],
            $observed,
            "PHP {$minor} does it differently: " . \json_encode($observed),
        );
    }

    /**
     * The expectation of a version: keys are a minor (`8.4`) or a range of them (`8.1-8.3`).
     *
     * @param array<string, array{gone?: list<string>, new?: list<string>, ops: '-'|'0'|'+'}> $expect
     * @return array{gone?: list<string>, new?: list<string>, ops: '-'|'0'|'+'}|null
     */
    private static function expectation(array $expect, string $minor): ?array
    {
        foreach ($expect as $versions => $expectation) {
            [$from, $to] = \array_pad(\explode('-', $versions, 2), 2, $versions);
            if (\version_compare($minor, $from, '>=') && \version_compare($minor, $to, '<=')) {
                return $expectation;
            }
        }

        return null;
    }

    private function opcodes(string $code, string $name): DumpBlock
    {
        $file = "{$this->dir}/{$name}.php";
        \file_put_contents($file, "<?php\n\nnamespace App;\n\n{$code}\n");
        $dumps = [];
        (new OpcacheDumper(TestPhp::binary(), 1))->dump([$file], static function (FileDump $dump) use (&$dumps): void {
            $dumps[] = $dump;
        });
        Assert::count($dumps, 1);
        Assert::null($dumps[0]->error);
        foreach ((new DumpParser())->parse($dumps[0]->dump) as $block) {
            if ($block->name === 'App\f' && $block->phase === Phase::Opt) {
                return $block;
            }
        }

        throw new \LogicException("No App\\f in the dump of {$name}.");
    }
}
