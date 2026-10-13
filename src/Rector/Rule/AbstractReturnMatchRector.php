<?php

declare(strict_types=1);

namespace Opmin\Rector\Rule;

use Opmin\Rector\Support\FunctionBody;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PHPStan\Analyser\Scope;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;

/**
 * Replaces a run of statements that picks one of several `return`s by comparing one subject with
 * literals by `return match ($subject) { … };`: `match` compares through a jump table (`MATCH`)
 * instead of one comparison and one jump per literal.
 *
 * The subclasses find the run ({@see self::chainAt()}) and prove that `match` (strict, `===`)
 * chooses the same arm; this class walks every statement list of the function and replaces the run.
 *
 * @internal
 */
abstract class AbstractReturnMatchRector extends AbstractRector implements ConfigurableRectorInterface
{
    public const string MIN_ARMS = 'min_arms';

    /**
     * Point where `match` pays off, counted in literals (the `default` arm aside), measured on PHP 8.1
     * and 8.5. Without a return type (or with `mixed`) the optimizer moves the `return` into every
     * arm: 2 literals save 3 opcodes, every next one 2 more.
     */
    public const int DEFAULT_MIN_ARMS = 2;

    /**
     * With a return type the arms of `match` keep an assignment and a jump each: 2 literals save
     * nothing when the results need no type check (literals, typed variables), 3 save 1, 5 save 3.
     */
    public const int DEFAULT_MIN_ARMS_TYPED = 3;

    protected const string INT = 'int';
    protected const string STRING = 'string';

    /** @var positive-int|null Null: `auto`, by the return type of the function. */
    protected ?int $minArms = null;

    /** @var positive-int */
    private int $threshold = self::DEFAULT_MIN_ARMS;

    /**
     * Short name for `#[\Opmin\Ignore(rules: [...])]` and `@opmin-ignore name`.
     *
     * @return non-empty-string
     */
    abstract public static function alias(): string;

    /**
     * @param array<array-key, mixed> $configuration `min_arms`: a positive integer or `auto`.
     */
    public function configure(array $configuration): void
    {
        $minArms = $configuration[self::MIN_ARMS] ?? 'auto';
        if ($minArms === 'auto' || $minArms === null) {
            $this->minArms = null;
            return;
        }

        \is_int($minArms) && $minArms > 0 or throw new \InvalidArgumentException(
            static::class . ': `min_arms` must be a positive integer or `auto`, got ' . \get_debug_type($minArms) . '.',
        );
        $this->minArms = $minArms;
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
        # `return $x;` of a function returning by reference returns the variable, `return match` a value.
        # A dynamic scope (`extract()`, `$$name`, `eval`…) is left alone, as by every rule of opmin.
        if ($node->stmts === null || $node->byRef || !FunctionBody::allowsNewVariables($node)
            || FunctionBody::ignored($node, static::alias())
        ) {
            return null;
        }

        $typed = $node->returnType !== null
            && !($node->returnType instanceof Node\Identifier && $node->returnType->toLowerString() === 'mixed');
        $this->threshold = $this->minArms ?? ($typed ? self::DEFAULT_MIN_ARMS_TYPED : self::DEFAULT_MIN_ARMS);
        $changed = false;
        $node->stmts = $this->rewrite($node->stmts, $changed);
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
            $holder->stmts = $this->rewrite($holder->stmts, $changed);
        }

        return $changed ? $node : null;
    }

    /**
     * `int` or `string` for a literal `match` puts into its jump table, null for anything else
     * (a constant, a float, a mix of types: measured, `match` then saves nothing).
     */
    protected static function literalKind(Expr $expr): ?string
    {
        return match (true) {
            $expr instanceof Scalar\Int_, $expr instanceof Expr\UnaryMinus && $expr->expr instanceof Scalar\Int_ => self::INT,
            $expr instanceof Scalar\String_ => self::STRING,
            default => null,
        };
    }

    /**
     * The expression of a body that is exactly `return <expr>;`.
     *
     * @param array<Stmt> $stmts
     */
    protected static function singleReturn(array $stmts): ?Expr
    {
        return \count($stmts) === 1 && $stmts[0] instanceof Stmt\Return_ ? $stmts[0]->expr : null;
    }

    protected static function scope(Node $node): ?Scope
    {
        /** @var mixed $scope */
        $scope = $node->getAttribute(AttributeKey::SCOPE);

        return $scope instanceof Scope ? $scope : null;
    }

    /**
     * The run of statements starting at `$stmts[$i]` that `return match` replaces.
     *
     * @param list<Stmt> $stmts
     * @return array{positive-int, Expr, list<Node\MatchArm>, int}|null The number of statements, the
     *         subject, the arms (`default` included), the number of literals.
     */
    abstract protected function chainAt(array $stmts, int $i): ?array;

    /**
     * @param array<Stmt> $stmts
     * @return list<Stmt>
     */
    private function rewrite(array $stmts, bool &$changed): array
    {
        $stmts = \array_values($stmts);
        $result = [];
        for ($i = 0, $n = \count($stmts); $i < $n; ++$i) {
            $chain = $this->chainAt($stmts, $i);
            if ($chain === null || $chain[3] < $this->threshold) {
                $result[] = $stmts[$i];
                continue;
            }

            [$length, $subject, $arms] = $chain;
            $comments = [];
            foreach (\array_slice($stmts, $i, $length) as $replaced) {
                \array_push($comments, ...$replaced->getComments());
            }

            /** @var list<Comment> $comments */
            $result[] = new Stmt\Return_(new Expr\Match_($subject, $arms), $comments === [] ? [] : ['comments' => $comments]);
            $i += $length - 1;
            $changed = true;
        }

        return $result;
    }
}
