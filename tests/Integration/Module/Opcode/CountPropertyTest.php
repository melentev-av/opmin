<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Module\Opcode;

use Opmin\Module\Opcode\Dump\DumpParser;
use Opmin\Module\Opcode\Dump\FileDump;
use Opmin\Module\Opcode\Dump\OpcacheDumper;
use Opmin\Module\Opcode\FunctionCount;
use Opmin\Module\Opcode\Locate\DumpMatcher;
use Opmin\Module\Opcode\Locate\FunctionLocator;
use Opmin\Module\Opcode\Locate\MatchException;
use Opmin\Module\Opcode\Locate\UnitKind;
use Opmin\Tests\Integration\TestPhp;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Generated valid PHP (8.1 syntax) goes through the whole attribution chain under `php.binary`:
 * the dump parses, every block is paired with a function of the source, and every function of the
 * source gets a count — except closures the compiler drops in expressions it evaluates. Nested closures, anonymous classes and several closures on one line are the
 * cases where pairing by position could go wrong.
 */
#[Test]
#[Covers(DumpParser::class)]
#[Covers(DumpMatcher::class)]
#[Covers(FunctionLocator::class)]
final class CountPropertyTest
{
    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function everyFunctionOfGeneratedCodeIsCountedGenerators(): array
    {
        $expression = Gen::recursive(
            Gen::elements(['1', '$a', "'s'", 'null', '\strlen((string) $a)', '[1, 2]', 'PHP_EOL']),
            static fn(ArbitraryInterface $sub): ArbitraryInterface => Gen::map(
                Gen::tuple(Gen::intBetween(0, 9), $sub, $sub, Gen::bool()),
                static function (array $t): string {
                    [$kind, $x, $y, $newline] = $t;
                    $nl = $newline ? "\n" : ' ';

                    return match ($kind) {
                        0 => "({$x} + {$y})",
                        1 => "(fn(\$a) =>{$nl}{$x})",
                        2 => "(function (\$a) {{$nl}return {$x};{$nl}})",
                        3 => "(new class {{$nl}public function m(\$a) { return {$x}; }{$nl}})",
                        4 => "({$x} ? {$y} : {$x})",
                        5 => "match ({$x}) { 1 => {$y},{$nl}default => {$x} }",
                        6 => "(static function () use (\$a) { \$f = fn() => {$x}; return \$f; })",
                        7 => "[{$x}, {$y}]",
                        8 => "[fn() => {$x}, fn() => {$y}]",
                        default => "(new class(\$a) extends \\ArrayObject { public function n() { return fn() => {$x}; } })",
                    };
                },
            ),
            maxDepth: 3,
        );
        $unit = Gen::map(
            Gen::tuple(Gen::intBetween(0, 5), $expression),
            static fn(array $t): array => $t,
        );

        return ['units' => Gen::arrayOf($unit, 1, 5)];
    }

    /**
     * @param list<array{int, string}> $units
     */
    #[Property(runs: 40)]
    public function everyFunctionOfGeneratedCodeIsCounted(array $units): void
    {
        $code = self::file($units);
        $file = \tempnam(\sys_get_temp_dir(), 'opmin-gen') . '.php';
        \file_put_contents($file, $code);

        try {
            $dump = null;
            (new OpcacheDumper(TestPhp::binary(), workers: 1))->dump([$file], static function (FileDump $d) use (&$dump): void {
                $dump = $d;
            });
            Gen::note('code', $code);
            Assert::null($dump?->error);
            $units = (new FunctionLocator())->locate($code, 'gen.php::<main>');
            try {
                $counts = (new DumpMatcher())->match($units, (new DumpParser())->parse((string) $dump?->dump), 'gen.php');
            } catch (MatchException $e) {
                # A closure the compiler dropped next to a compiled one on the same line: no count, no guess.
                Assert::string($e->getMessage())->contains('Cannot tell which closure on line');
                return;
            }
        } finally {
            @\unlink($file);
        }

        $counted = \array_map(static fn(FunctionCount $c): string => $c->key, $counts);
        $missing = [];
        foreach ($units as $unit) {
            $unit->abstract || \in_array($unit->key, $counted, true) or $missing[] = $unit;
        }

        Assert::same(\count(\array_unique($counted)), \count($counted));
        # Only closures the compiler dropped (`[null ? fn() => 1 : null]`) and what is inside them have no count.
        foreach ($missing as $unit) {
            Assert::true($unit->kind === UnitKind::Closure || \str_contains($unit->key, '::{closure:'), $unit->key);
        }
    }

    /**
     * @param list<array{int, string}> $units
     */
    private static function file(array $units): string
    {
        $code = "<?php\nnamespace Gen;\n";
        foreach ($units as $i => [$kind, $e]) {
            $code .= match ($kind) {
                0 => "function f{$i}(\$a) { return {$e}; }\n",
                1 => "class C{$i} {\n public function m(\$a) { return {$e}; }\n public static function s(\$a) { \$c = {$e}; return \$c; }\n abstract public function x(); }\n",
                2 => "\$v{$i} = {$e};\n",
                3 => "trait T{$i} { public function t(\$a) { return {$e}; } }\ninterface I{$i} { public function i(); }\n",
                4 => "enum E{$i}: int { case A = 1; public function e(\$a) { return {$e}; } }\n",
                default => "if (true) { function g{$i}(\$a) { return {$e}; } }\n",
            };
        }

        # An abstract method needs an abstract class.
        return \str_replace("class C", "abstract class C", $code);
    }
}
