<?php

declare(strict_types=1);

namespace Opmin\Rector\Rule;

use Opmin\Rector\Support\FunctionBody;
use Opmin\Rector\Support\Reprint;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PHPStan\Analyser\Scope;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * `return match (true) { $a > 1 => 'x', default => 'y' };` → `if ($a > 1) { return 'x'; } return 'y';`
 * and `$v = match (true) { … };` → `if (…) { $v = …; } elseif (…) { … } else { … }`.
 *
 * `match (true)` jumps into the arm and then to the end, and on PHP 8.5 compares every condition with
 * `true` (`TYPE_CHECK`) first; `if` jumps past the arm on the condition itself (`tests/Cases/`): 24–29 % fewer CPU instructions per call without
 * the JIT, 14–16 % with the tracing JIT (`docs/rule-cost.md`). The `if` is one level deeper than the
 * `match`, so the rule allows one level more than `readability.max_nesting_increase`
 * ({@see self::NESTING_ALLOWANCE}). Off by default: it trades a common, readable form for speed.
 *
 * Fires only when the result is provably the same:
 * - the subject is the literal `true` and there is a `default` arm (`UnhandledMatchError` aside);
 * - by native type every condition is a `bool`: `match` compares with `===`, `if` takes any truthy
 *   value (`1`, `'a'`);
 * - every arm has one condition: `c1, c2 =>` would become `c1 || c2`, a branch more for the
 *   readability check;
 * - the `match` is a whole `return` (not in a function returning by reference: `return $x` returns
 *   the variable there, `return match` a value) or a whole assignment to a local variable.
 *
 * An arm that throws becomes a `throw` statement. The conditions keep their order: `if` evaluates them
 * one by one, as `match` does.
 *
 * @internal
 */
#[TestRectorFixtures('Fixture/MatchTrueToIf')]
final class MatchTrueToIfRector extends AbstractRector
{
    /** Levels of nesting the rewrite may add over `readability.max_nesting_increase`. */
    public const int NESTING_ALLOWANCE = 1;

    public static function alias(): string
    {
        return 'match_to_if';
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Turn match (true) with bool conditions into a chain of if', [
            new ConfiguredCodeSample(
                "return match (true) { \$n < 0 => 'negative', \$n === 0 => 'zero', default => 'positive' };",
                "if (\$n < 0) { return 'negative'; } if (\$n === 0) { return 'zero'; } return 'positive';",
                [],
            ),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [Stmt\ClassMethod::class, Stmt\Function_::class, Expr\Closure::class];
    }

    /**
     * @param Stmt\ClassMethod|Stmt\Function_|Expr\Closure $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node->stmts === null || FunctionBody::ignored($node, self::alias())) {
            return null;
        }

        $changed = false;
        $node->stmts = $this->rewrite($node->stmts, $node->byRef, $changed);
        $holders = [];
        foreach (FunctionBody::ownNodes($node) as $inner) {
            if ($inner instanceof Stmt\If_ || $inner instanceof Stmt\ElseIf_ || $inner instanceof Stmt\Else_
                || $inner instanceof Stmt\For_ || $inner instanceof Stmt\Foreach_ || $inner instanceof Stmt\While_
                || $inner instanceof Stmt\Do_ || $inner instanceof Stmt\Case_ || $inner instanceof Stmt\TryCatch
                || $inner instanceof Stmt\Catch_ || $inner instanceof Stmt\Finally_ || $inner instanceof Stmt\Block
            ) {
                $holders[] = $inner;
            }
        }

        foreach ($holders as $holder) {
            $holder->stmts = $this->rewrite($holder->stmts, $node->byRef, $changed);
        }

        return $changed ? $node : null;
    }

    /**
     * The arms of a `match (true)` the rule rewrites: the conditional ones in order, then `default`.
     *
     * @return array{non-empty-list<array{Expr, Expr}>, Expr}|null [[condition, body]…], the body of `default`.
     */
    private static function arms(Expr\Match_ $match): ?array
    {
        if (!$match->cond instanceof Expr\ConstFetch || $match->cond->name->toLowerString() !== 'true') {
            return null;
        }

        $arms = [];
        $default = null;
        foreach ($match->arms as $arm) {
            if ($arm->conds === null) {
                $default = $arm->body;
                continue;
            }

            if (\count($arm->conds) !== 1 || !self::isBool($arm->conds[0])) {
                return null;
            }

            $arms[] = [$arm->conds[0], $arm->body];
        }

        return $arms === [] || $default === null ? null : [$arms, $default];
    }

    private static function isBool(Expr $expr): bool
    {
        /** @var mixed $scope */
        $scope = $expr->getAttribute(AttributeKey::SCOPE);

        return $scope instanceof Scope && $scope->getNativeType($expr)->isBoolean()->yes();
    }

    /**
     * `return <body>;` or `$v = <body>;`, a body that throws as `throw …;`.
     *
     * @param non-empty-string|null $variable The local variable assigned; null: the form of `return`.
     */
    private static function statement(Expr $body, ?string $variable): Stmt
    {
        if ($body instanceof Expr\Throw_) {
            return new Stmt\Expression($body);
        }

        if ($variable === null) {
            return new Stmt\Return_($body);
        }

        Reprint::prepare($body, Reprint::LOOSER_THAN_ASSIGN);

        return new Stmt\Expression(new Expr\Assign(new Expr\Variable($variable), $body));
    }

    /**
     * @param array<Stmt> $stmts
     * @return list<Stmt>
     */
    private function rewrite(array $stmts, bool $byRef, bool &$changed): array
    {
        $result = [];
        foreach ($stmts as $stmt) {
            $replacement = $this->replacement($stmt, $byRef);
            if ($replacement === null) {
                $result[] = $stmt;
                continue;
            }

            $comments = $stmt->getComments();
            $comments === [] or $replacement[0]->setAttribute('comments', $comments);
            \array_push($result, ...$replacement);
            $changed = true;
        }

        return $result;
    }

    /**
     * @return non-empty-list<Stmt>|null
     */
    private function replacement(Stmt $stmt, bool $byRef): ?array
    {
        $variable = null;
        if ($stmt instanceof Stmt\Return_ && $stmt->expr instanceof Expr\Match_ && !$byRef) {
            $match = $stmt->expr;
        } elseif ($stmt instanceof Stmt\Expression && $stmt->expr instanceof Expr\Assign && $stmt->expr->expr instanceof Expr\Match_
            && $stmt->expr->var instanceof Expr\Variable && \is_string($stmt->expr->var->name)
            && $stmt->expr->var->name !== '' && $stmt->expr->var->name !== 'this'
        ) {
            $match = $stmt->expr->expr;
            $variable = $stmt->expr->var->name;
        } else {
            return null;
        }

        $arms = self::arms($match);
        if ($arms === null) {
            return null;
        }

        foreach ([...\array_column($arms[0], 1), $arms[1]] as $body) {
            if ($variable !== null && !$body instanceof Expr\Throw_ && !Reprint::fits($body, Reprint::LOOSER_THAN_ASSIGN)) {
                return null;
            }
        }

        [$conditional, $default] = $arms;
        if ($variable === null) {
            # `return`: every arm leaves the function, `default` follows the chain.
            $result = [];
            foreach ($conditional as [$condition, $body]) {
                $result[] = new Stmt\If_($condition, ['stmts' => [self::statement($body, null)]]);
            }

            $result[] = self::statement($default, null);

            return $result;
        }

        $first = $conditional[0];
        $elseifs = \array_map(
            static fn(array $arm): Stmt\ElseIf_ => new Stmt\ElseIf_($arm[0], [self::statement($arm[1], $variable)]),
            \array_slice($conditional, 1),
        );

        return [new Stmt\If_($first[0], [
            'stmts' => [self::statement($first[1], $variable)],
            'elseifs' => $elseifs,
            'else' => new Stmt\Else_([self::statement($default, $variable)]),
        ])];
    }
}
