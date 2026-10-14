<?php

declare(strict_types=1);

namespace Opmin\Rector\Support;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinterAbstract;

/**
 * Parentheses for an original expression moved under a new operator.
 *
 * The format-preserving printer prints a node that has its `origNode` as its original text and
 * ignores the precedence it is printed at: under a new `.` the argument `$a || $b ? 1 : 2` loses the
 * parentheses it needs (`'x' . $a || $b ? 1 : 2`). A node without `origNode` is printed by the
 * pretty printer, which adds them — so such an expression is reprinted as a whole (its own layout
 * is lost, its meaning kept).
 *
 * @internal
 */
final class Reprint
{
    /** The php-parser precedence of `<<`/`>>`: in PHP 8 `.` binds looser than them, unlike the printer's table. */
    public const int LOOSER_THAN_CONCAT = 60;

    /** For an expression put in the place of a call: any operator may need parentheses there. */
    public const int ANYWHERE = -1000;

    /** The php-parser precedence of `=`: its right side takes anything up to `and`/`or`/`xor`. */
    public const int LOOSER_THAN_ASSIGN = 160;

    /** @var array<class-string, array{int, int, int}>|null */
    private static ?array $precedence = null;

    /**
     * The php-parser precedence of the expression; null for one that binds tightest (a variable, a call,
     * a literal…).
     */
    public static function precedence(Expr $expr): ?int
    {
        if (self::$precedence === null) {
            /** @var array<class-string, array{int, int, int}> $map */
            $map = (new \ReflectionClass(PrettyPrinterAbstract::class))->getDefaultProperties()['precedenceMap'];
            self::$precedence = $map;
        }

        return self::$precedence[$expr::class][0] ?? null;
    }

    /**
     * Whether `$expr` can be put where only expressions binding at least as tight as `$limit` fit:
     * it does, or the printer reprints it right. php-parser still ranks `.` with `+`/`-` (its table
     * predates PHP 8), so a reprint drops the parentheses of a `.` under `+`, `-`, `<<` or `>>`.
     */
    public static function fits(Expr $expr, int $limit): bool
    {
        if (!self::looser($expr, $limit)) {
            return true;
        }

        return (new NodeFinder())->findFirst($expr, static fn(Node $n): bool => self::concatMisprintedUnder($n)
            && $n instanceof BinaryOp && ($n->left instanceof BinaryOp\Concat || $n->right instanceof BinaryOp\Concat)) === null;
    }

    /**
     * Makes the printer reprint `$expr` (checked with {@see self::fits()}) when it binds looser than
     * `$limit`, so it gets the parentheses its new place needs.
     */
    public static function prepare(Expr $expr, int $limit): void
    {
        if (!self::looser($expr, $limit)) {
            return;
        }

        foreach ((new NodeFinder())->find($expr, static fn(Node $n): bool => true) as $node) {
            $node->setAttribute('origNode', null);
        }
    }

    /**
     * Whether a `.` put in place of an operand of `$parent` would be printed without the parentheses
     * PHP 8 needs: php-parser ranks `.` with `+`/`-` and above `<<`/`>>`.
     */
    public static function concatMisprintedUnder(Node $parent): bool
    {
        return $parent instanceof BinaryOp\Plus || $parent instanceof BinaryOp\Minus
            || $parent instanceof BinaryOp\ShiftLeft || $parent instanceof BinaryOp\ShiftRight;
    }

    private static function looser(Expr $expr, int $limit): bool
    {
        $precedence = self::precedence($expr);

        return $precedence !== null && $precedence > $limit;
    }
}
