<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Optimize;

use Opmin\Module\Config\Schema;
use Opmin\Module\Optimize\Readability;
use Opmin\Module\Optimize\Rector\Gain;
use PhpParser\Node\Stmt\Function_;
use PhpParser\ParserFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(Readability::class)]
final class ReadabilityTest
{
    /**
     * @return iterable<string, array{string, int, int}> [body, complexity, nesting]
     */
    public static function metrics(): iterable
    {
        yield 'straight line' => ['return $a + 1;', 1, 0];
        yield 'if/elseif/else' => ['if ($a) { return 1; } elseif ($b) { return 2; } else { return 3; }', 3, 1];
        yield 'loops nest' => ['foreach ($a as $x) { while ($x) { for (;;) { break; } } }', 4, 3];
        yield 'boolean operators and ternary' => ['return $a && $b || $c ? 1 : ($d ?? 2);', 5, 0];
        yield 'match arms, default does not count' => ["return match (\$a) { 1 => 'a', 2, 3 => 'b', default => 'c' };", 3, 0];
        yield 'switch cases and catch' => ['switch ($a) { case 1: break; default: break; } try { f(); } catch (\Exception $e) {}', 3, 1];
        yield 'closures are units of their own' => ['return fn($x) => $x ? 1 : 2;', 1, 0];
    }

    #[DataProvider('metrics')]
    public function measuresComplexityAndNesting(string $body, int $complexity, int $nesting): void
    {
        $function = self::function($body);

        Assert::same(Readability::complexity($function), $complexity);
        Assert::same(Readability::nesting($function), $nesting);
    }

    public function findsForbiddenPatterns(): void
    {
        $function = self::function('if ($x = f()) { goto end; } end: return $a ? ($b ? 1 : 2) : 3;');

        Assert::same(Readability::patterns($function), ['nested_ternary' => 1, 'assignment_in_condition' => 1, 'goto' => 1]);
    }

    #[DataSet([1, 1, Gain::Opcodes, null], 'a gain of one on one line')]
    #[DataSet([0, 1, Gain::Opcodes, 'saves no opcodes'], 'no gain')]
    #[DataSet([-2, 1, Gain::Opcodes, 'opcodes grew by 2'], 'growth')]
    #[DataSet([1, 3, Gain::Opcodes, 'saves 1 opcode(s) for 3 changed line(s), less than readability.min_gain_per_line 0.5 per line'], 'not worth the lines')]
    #[DataSet([0, 3, Gain::Executed, null], 'executed gain: not growing is enough')]
    #[DataSet([-1, 1, Gain::Executed, 'opcodes grew by 1'], 'executed gain: growth')]
    #[DataSet([-2, 3, Gain::Cost, null], 'cost gain: growth is not a reason')]
    #[DataSet([0, 3, Gain::Cost, null], 'cost gain: the same count')]
    public function rejectsChangesNotWorthIt(int $gain, int $lines, Gain $kind, ?string $reason): void
    {
        Assert::same((new Readability(new Schema\Readability()))->reject($gain, $lines, null, null, $kind), $reason);
    }

    public function rejectsGrowingShape(): void
    {
        $readability = new Readability(new Schema\Readability());
        $flat = self::function('return $a;');

        Assert::same($readability->compare($flat, self::function('return $a ? 1 : 2;')), 'cyclomatic complexity grew by 1 (more branches; readability.max_cyclomatic_increase is 0)');
        Assert::same($readability->compare(self::function('if ($a) { return 1; } return 2;'), self::function('if ($a) { if ($b) { return 1; } } return 2;')), 'cyclomatic complexity grew by 1 (more branches; readability.max_cyclomatic_increase is 0)');
        Assert::same($readability->compare(self::function('if ($a) {} if ($b) {}'), self::function('if ($a) { if ($b) {} }')), 'nesting grew by 1 (code more levels deep; readability.max_nesting_increase is 0)');
        Assert::same($readability->compare(self::function('$x = f(); if ($x) {}'), self::function('if ($x = f()) {}')), 'introduces assignment_in_condition (forbidden by readability.forbid_patterns)');
        Assert::same($readability->compare(self::function('return $a;'), self::function('return $a;')), null);
        # A rule may allow more nesting (MatchTrueToIfRector::NESTING_ALLOWANCE).
        $match = self::function('return match (true) { $a > 1 => 1, default => 2 };');
        $if = self::function('if ($a > 1) { return 1; } return 2;');
        Assert::same($readability->compare($match, $if), 'nesting grew by 1 (code more levels deep; readability.max_nesting_increase is 0)');
        Assert::same($readability->compare($match, $if, 1), null);
        $match3 = self::function('return match (true) { $a > 1 => 1, $b > 1 => 2, default => 3 };');
        Assert::same($readability->compare($match3, self::function('if ($a > 1) { if ($b > 1) { return 2; } return 1; } return 3;'), 1), 'nesting grew by 2 (code more levels deep; readability.max_nesting_increase is 0, the rule allows 1 more)');
    }

    private static function function(string $body): Function_
    {
        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse("<?php function f(\$a, \$b = 0, \$c = 0, \$d = 0) { {$body} }");
        $function = $stmts[0] ?? null;
        Assert::instanceOf($function, Function_::class);

        /** @var Function_ $function */
        return $function;
    }
}
