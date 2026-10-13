<?php

declare(strict_types=1);

namespace Opmin\Rector\Rule;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * `switch ($s) { case 'a': return 1; case 'b': return 2; default: return 0; }` →
 * `return match ($s) { 'a' => 1, 'b' => 2, default => 0 };` (catalog B2).
 *
 * Measured on PHP 8.1 / 8.5 without a return type: 2 labels −3 (−5 with string labels on 8.5),
 * 3 labels −5 / −7, 5 labels −9 / −11; with one and literal results: 2 labels 0 (−2 with string
 * labels on 8.5), 3 labels −1 / −3, 5 labels −3 / −5 (`min_arms`, {@see self::DEFAULT_MIN_ARMS_TYPED}).
 *
 * `switch` compares by `==`, `match` by `===`. Fires only when the two agree:
 * - the native type of the subject is `int` and every label is an int literal, or it is `string`
 *   and every label is a string literal that is not numeric (`'1' == '01'`, `'1e1' == '10'`);
 *   phpdoc types are not trusted — a caller may pass anything;
 * - every case is exactly `return <expr>;` (a `break;` after it is dead and dropped) or empty,
 *   falling into the next one (`case 1: case 2:` → `1, 2 =>`); code that falls through, `break`,
 *   `continue` or more statements leave the `switch` alone;
 * - there is a `default` with its own `return`, or the `switch` is followed by `return <expr>;`:
 *   `match` without `default` throws `UnhandledMatchError` where `switch` falls out;
 * - the function does not return by reference.
 *
 * The subject is evaluated once by both, so it may be any expression.
 *
 * @internal
 */
#[TestRectorFixtures('Fixture/SwitchToMatch')]
final class SwitchToMatchRector extends AbstractReturnMatchRector
{
    public static function alias(): string
    {
        return 'switch_to_match';
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Replace a `switch` of returns by `return match` where `==` and `===` agree', [
            new ConfiguredCodeSample(
                <<<'PHP'
                    function label(string $status): string
                    {
                        switch ($status) {
                            case 'new':
                            case 'open':
                                return 'active';
                            case 'done':
                                return 'closed';
                            default:
                                return 'unknown';
                        }
                    }
                    PHP,
                <<<'PHP'
                    function label(string $status): string
                    {
                        return match ($status) {
                            'new', 'open' => 'active',
                            'done' => 'closed',
                            default => 'unknown',
                        };
                    }
                    PHP,
                [self::MIN_ARMS => 'auto'],
            ),
        ]);
    }

    protected function chainAt(array $stmts, int $i): ?array
    {
        $switch = $stmts[$i];
        $kind = $switch instanceof Stmt\Switch_ ? $this->subjectKind($switch->cond) : null;
        if (!$switch instanceof Stmt\Switch_ || $kind === null) {
            return null;
        }

        $arms = [];
        $labels = [];
        $literals = 0;
        $default = false;
        foreach ($switch->cases as $case) {
            if ($case->cond === null) {
                $result = self::caseReturn($case->stmts);
                # `case 1: default:` — `default` cannot share an arm.
                if ($labels !== [] || $result === null) {
                    return null;
                }

                $arms[] = new Node\MatchArm(null, $result);
                $default = true;
                continue;
            }

            if (!self::sameAsIdentical($case->cond, $kind)) {
                return null;
            }

            $labels[] = $case->cond;
            ++$literals;
            if ($case->stmts === []) {
                continue;
            }

            $result = self::caseReturn($case->stmts);
            if ($result === null) {
                return null;
            }

            $arms[] = new Node\MatchArm($labels, $result);
            $labels = [];
        }

        # A trailing empty case falls out of the `switch`.
        if ($labels !== []) {
            return null;
        }

        if ($default) {
            return [1, $switch->cond, $arms, $literals];
        }

        $next = $stmts[$i + 1] ?? null;

        return $next instanceof Stmt\Return_ && $next->expr !== null
            ? [2, $switch->cond, [...$arms, new Node\MatchArm(null, $next->expr)], $literals]
            : null;
    }

    /**
     * A label `==` compares with a subject of `$kind` exactly as `===` does: an int literal with an
     * int, a non-numeric string literal with a string (two numeric strings compare as numbers).
     */
    private static function sameAsIdentical(Expr $label, string $kind): bool
    {
        return self::literalKind($label) === $kind && (!$label instanceof Scalar\String_ || !\is_numeric($label->value));
    }

    /**
     * The expression of a case body `return <expr>;`, optionally followed by a dead `break;`.
     *
     * @param array<Stmt> $stmts
     */
    private static function caseReturn(array $stmts): ?Expr
    {
        \count($stmts) === 2 && $stmts[1] instanceof Stmt\Break_ && $stmts[1]->num === null and $stmts = [$stmts[0]];

        return self::singleReturn($stmts);
    }

    /**
     * `int` or `string` by the native type of the subject; null when `==` may differ from `===`.
     */
    private function subjectKind(Expr $subject): ?string
    {
        $type = self::scope($subject)?->getNativeType($subject);

        return match (true) {
            $type === null => null,
            $type->isInteger()->yes() => self::INT,
            $type->isString()->yes() => self::STRING,
            default => null,
        };
    }
}
