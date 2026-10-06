<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification\Coverage;

use Opmin\Module\Opcode\Locate\FunctionLocator;
use Opmin\Module\Verification\Coverage\Instrumenter;
use PhpParser\ParserFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Instrumenter::class)]
final class InstrumenterTest
{
    /**
     * Function bodies and the number of probes: [body, probes, a fragment of the result].
     *
     * @return iterable<string, array{string, int, string}>
     */
    public static function bodies(): iterable
    {
        yield 'entry only' => ['return $a + 1;', 1, 'Probe::hit(0); return'];
        yield 'if with implicit else' => ['if ($a) { return 1; } return 2;', 3, '} else { \Opmin\Harness\Probe::hit(2); }'];
        yield 'if/elseif/else' => ['if ($a) { $b = 1; } elseif ($b) { $b = 2; } else { $b = 3; } return $b;', 4, 'else { \Opmin\Harness\Probe::hit(3);'];
        yield 'body without braces' => ['if ($a) return 1; return 2;', 3, 'if ($a) { \Opmin\Harness\Probe::hit(1); return 1; } else'];
        yield 'else if chain' => ['if ($a) return 1; else if ($b) return 2; return 3;', 5, 'else { \Opmin\Harness\Probe::hit(2); if ($b) { \Opmin\Harness\Probe::hit(3); return 2; } else { \Opmin\Harness\Probe::hit(4); } }'];
        yield 'alternative syntax gets no else' => ["if (\$a):\n return 1;\nendif;\nreturn 2;", 2, 'endif;'];
        yield 'loops' => ['foreach ($a as $x) { $b += $x; } while ($b > 9) $b--; return $b;', 3, 'while ($b > 9) { \Opmin\Harness\Probe::hit(2); $b--; }'];
        yield 'switch cases' => ['switch ($a) { case 1: return 1; case 2: case 3: return 2; default: return 0; }', 4, 'case 2: case 3: \Opmin\Harness\Probe::hit(2);'];
        yield 'catch and finally' => ['try { $b = 1; } catch (\Exception $e) { $b = 2; } finally { $b++; } return $b;', 3, 'finally { \Opmin\Harness\Probe::hit(2);'];
        yield 'ternary and coalesce' => ['return $a ? 1 : ($b ?? 2);', 4, '$a ? (\Opmin\Harness\Probe::hit(1) ?? (1))'];
        yield 'short ternary' => ['return $a ?: $b;', 2, '$a ?: (\Opmin\Harness\Probe::hit(1) ?? ($b))'];
        yield 'boolean operators' => ['return $a && $b || $c;', 3, '$a && (\Opmin\Harness\Probe::hit(2) ?? ($b))'];
        yield 'match arms' => ['return match ($a) { 1 => "x", default => throw new \Exception() };', 3, 'default => (\Opmin\Harness\Probe::hit(2) ?? (throw new \Exception()))'];
        yield 'nested closure is not instrumented' => ['$f = function () { if (true) { return 1; } }; return $f;', 1, 'function () { if (true) { return 1; } }'];
        yield 'static initializer is not instrumented' => ['static $s = 1 ? 2 : 3; return $s;', 1, 'static $s = 1 ? 2 : 3;'];
        yield 'empty function' => ['', 0, 'function f($a = null, $b = null, $c = null) {  }'];
    }

    #[DataProvider('bodies')]
    public function insertsProbesWithoutMovingLines(string $body, int $probes, string $fragment): void
    {
        $code = "<?php\nnamespace App;\nfunction f(\$a = null, \$b = null, \$c = null) { {$body} }\nfunction g() { return 1; }\n";

        [$result, $count] = (new Instrumenter())->instrument($code, self::node($code, 'App\f'));

        Assert::same($count, $probes);
        Assert::string($result)->contains($fragment);
        Assert::same(\substr_count($result, "\n"), \substr_count($code, "\n"));
        Assert::string($result)->contains('function g() { return 1; }');
        (new ParserFactory())->createForNewestSupportedVersion()->parse($result);
    }

    public function instrumentsArrowFunctions(): void
    {
        $code = "<?php\n\$f = fn(\$x) => \$x > 1 ? 'a' : 'b';\n";

        [$result, $count] = (new Instrumenter())->instrument($code, self::node($code, 'x.php::<main>::{closure:1}'));

        Assert::same($count, 3);
        Assert::string($result)->contains("fn(\$x) => (\\Opmin\\Harness\\Probe::hit(0) ?? (\$x > 1 ? (\\Opmin\\Harness\\Probe::hit(1) ?? ('a'))");
    }

    private static function node(string $code, string $key): \PhpParser\Node\FunctionLike
    {
        foreach ((new FunctionLocator())->locate($code, 'x.php::<main>') as $unit) {
            if ($unit->key === $key && $unit->node !== null) {
                return $unit->node;
            }
        }

        throw new \LogicException("No {$key}");
    }
}
