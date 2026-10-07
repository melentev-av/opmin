<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification\Coverage;

use Opmin\Module\Verification\Coverage\Dead;
use Opmin\Module\Verification\Coverage\DeadBranches;
use Opmin\Module\Verification\Coverage\Instrumenter;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

/**
 * Which probes and lines PHPStan's errors make dead. The function is `f` and starts on line 2.
 */
#[Test]
#[Covers(DeadBranches::class)]
#[Covers(Dead::class)]
final class DeadBranchesTest
{
    #[DataSet(["if (is_int(\$x)) {\n  return 1;\n} else {\n  return 2;\n}", 3, 'function.alreadyNarrowedType', [2], [6, 7]], 'narrowed type: the else')]
    #[DataSet(["if (is_string(\$x)) {\n  return 1;\n}\nreturn 2;", 3, 'function.impossibleType', [1], [4]], 'impossible type: the if body')]
    #[DataSet(["if (true) {\n  return 1;\n}\nreturn 2;", 3, 'if.alwaysTrue', [2], []], 'constant condition: the added else')]
    #[DataSet(["if (\$x === null) {\n  return 1;\n}\nreturn 2;", 3, 'identical.alwaysFalse', [1], [4]], 'identical')]
    #[DataSet(["if (\$x > 1) {\n  return 1;\n} elseif (is_string(\$x)) {\n  return 2;\n} else {\n  return 3;\n}", 5, 'function.impossibleType', [2], [6]], 'elseif body')]
    #[DataSet(["if (\$x > 1) {\n  return 1;\n} elseif (is_int(\$x)) {\n  return 2;\n} elseif (\$x) {\n  return 3;\n} else {\n  return 4;\n}", 5, 'function.alreadyNarrowedType', [3, 4], [8, 9, 10, 11]], 'elseif always true: the rest')]
    #[DataSet(["if (\$x > 1) {\n  return 1;\n} elseif (is_int(\$x)) {\n  return 2;\n}\nreturn 3;", 5, 'function.alreadyNarrowedType', [3], []], 'elseif always true: the added else')]
    #[DataSet(["return \$x instanceof \\stdClass ? 1 : 2;", 3, 'instanceof.alwaysTrue', [2], []], 'ternary else')]
    #[DataSet(["return is_string(\$x) ? 1 : 2;", 3, 'function.impossibleType', [1], []], 'ternary if')]
    #[DataSet(["return is_int(\$x) || \$x > 3;", 3, 'function.alreadyNarrowedType', [1], []], 'right of ||')]
    #[DataSet(["return is_string(\$x) && \$x > 3;", 3, 'function.impossibleType', [1], []], 'right of &&')]
    #[DataSet(["return is_int(\$x) && \$x > 3;", 3, 'function.alreadyNarrowedType', [], []], '&& with a true left side: nothing')]
    #[DataSet(["return \$x > 0 || \$x;", 3, 'booleanOr.leftAlwaysTrue', [1], []], 'booleanOr.leftAlwaysTrue')]
    #[DataSet(["return \$x > 0 && \$x;", 3, 'booleanAnd.leftAlwaysFalse', [1], []], 'booleanAnd.leftAlwaysFalse')]
    #[DataSet(["while (is_string(\$x)) {\n  \$x++;\n}\nreturn \$x;", 3, 'function.impossibleType', [1], [4]], 'while body')]
    #[DataSet(["while (\$x > 0) {\n  \$x--;\n}\nreturn \$x;", 3, 'while.alwaysFalse', [1], [4]], 'while.alwaysFalse')]
    #[DataSet(["return match (true) {\n  is_string(\$x) => 1,\n  default => 2,\n};", 4, 'function.impossibleType', [1], []], 'arm of match (true)')]
    #[DataSet(["return match (\$x) {\n  1 => 'a',\n  default => 'b',\n};", 4, 'match.alwaysFalse', [1], []], 'match.alwaysFalse')]
    #[DataSet(["return 1;\necho 'never';\necho 'never';", 4, 'deadCode.unreachable', [], [4, 5]], 'unreachable statements')]
    #[DataSet(["return 1; echo 1;\necho 2;", 3, 'deadCode.unreachable', [], [4]], 'unreachable after a live statement on its line')]
    #[DataSet(["if (\$x) {\n  if (is_string(\$x)) {\n    if (\$x === 'a') {\n      return 1;\n    }\n  }\n}\nreturn 2;", 4, 'function.impossibleType', [3, 5, 6], [5, 6, 7]], 'nested branches die with their parent')]
    #[DataSet(["if (is_int(\$x)) return 1;\nreturn 2;", 3, 'function.alreadyNarrowedType', [2], []], 'braceless if')]
    #[DataSet(["if (is_string(\$x)) return 1;\nreturn 2;", 3, 'function.impossibleType', [1], []], 'braceless if, its body')]
    public function deadCodeOfAnError(string $body, int $line, string $identifier, array $probes, array $lines): void
    {
        $this->check($body, [['line' => $line, 'identifier' => $identifier, 'message' => '']], $probes, $lines);
    }

    #[DataSet(["return \$x ?? 7;", 'Variable $x on left side of ?? always exists and is not nullable.', [1]], 'not nullable: the right side')]
    #[DataSet(["return \$a['k'] ?? 7;", "Offset 'k' on array{} on left side of ?? does not exist.", []], 'missing offset: the right side always runs')]
    public function nullCoalesce(string $body, string $message, array $probes): void
    {
        $this->check($body, [['line' => 3, 'identifier' => 'nullCoalesce.variable', 'message' => $message]], $probes, []);
    }

    #[DataSet(["return \$x > 5 && is_string(\$x) ? 2 : 3;", 'function.impossibleType'], 'only a part of the condition')]
    #[DataSet(["return is_string(\$x) || is_string(\$y) ? 2 : 3;", 'function.impossibleType'], 'two calls on the line')]
    #[DataSet(["if (\$x) { return 1; } if (\$y) { return 2; }\nreturn 3;", 'if.alwaysTrue'], 'two ifs on the line')]
    #[DataSet(["\$x++; return 1; echo 1;\necho 2;", 'deadCode.unreachable'], 'two statements on the line')]
    #[DataSet(["return strlen(\$x) > 1;", 'function.alreadyNarrowedType'], 'not a condition')]
    #[DataSet(["return is_int(\$x) ? 1 : 2;", 'function.resultUnused'], 'unknown identifier')]
    #[DataSet(["return is_int(\$x) ? 1 : 2;", 'function'], 'identifier without a suffix')]
    #[DataSet(["return \$x > 0 && \$x;", 'booleanAnd.alwaysFalse'], 'whole && always false')]
    #[DataSet(["if (is_int(\$x)) {\n  return 1;\n}\nreturn 2;", 'if.alwaysTrue', 4], 'another line')]
    #[DataSet(["return match (\$x) {\n  1, 2 => 'a',\n  default => 'b',\n};", 'function.impossibleType', 4], 'arm of a match on a value')]
    public function uncertainErrorsKillNothing(string $body, string $identifier, int $line = 3): void
    {
        $this->check($body, [['line' => $line, 'identifier' => $identifier, 'message' => '']], [], []);
    }

    public function closuresAndClassesInsideAreNotTheFunction(): void
    {
        $body = "\$f = function () use (\$x) { if (is_int(\$x)) { return 1; } return 2; };\nreturn \$f();";

        $this->check($body, [['line' => 3, 'identifier' => 'function.alreadyNarrowedType', 'message' => '']], [], []);
    }

    public function errorsOfSeveralBranchesAddUp(): void
    {
        $body = "if (is_string(\$x)) {\n  return 1;\n}\nreturn \$x ?? 2;";
        $errors = [
            ['line' => 3, 'identifier' => 'function.impossibleType', 'message' => ''],
            ['line' => 6, 'identifier' => 'nullCoalesce.variable', 'message' => 'is not nullable'],
        ];

        $this->check($body, $errors, [1, 3], [4]);
        Assert::true(DeadBranches::find(self::function("function f(\$x) {\n  return 1;\n}"), [])->isEmpty());
    }

    private static function function(string $code): Function_
    {
        $code = \str_starts_with($code, '<?php') ? $code : "<?php\n" . $code;
        $function = (new NodeFinder())->findFirstInstanceOf((new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [], Function_::class);
        \assert($function instanceof Function_);

        return $function;
    }

    /**
     * @param list<array{line: int, identifier: string, message: string}> $errors
     * @param list<int> $probes
     * @param list<int> $lines
     */
    private function check(string $body, array $errors, array $probes, array $lines): void
    {
        $code = "<?php\nfunction f(\$x, \$y = null, \$a = []) {\n" . $body . "\n}\n";
        $function = self::function($code);
        $instrumenter = new Instrumenter();
        $instrumenter->instrument($code, $function);
        $dead = DeadBranches::find($function, \array_map(static fn(array $e): array => ['file' => 'f.php'] + $e, $errors));

        Assert::same($dead->probes($instrumenter->sites()), $probes);
        Assert::same($dead->lines($code), $lines);
    }
}
