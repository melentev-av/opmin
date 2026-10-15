<?php

declare(strict_types=1);

namespace Opmin\Rector\Rule;

use Opmin\Rector\Support\FunctionBody;
use Opmin\Rector\Support\GlobalFunctionCall;
use Opmin\Rector\Support\Reprint;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitor;
use PHPStan\Analyser\Scope;
use PHPStan\Type\IntegerType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Php\PhpVersionProvider;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * `\sprintf('%s-%s', $a, $b)` → `$a . '-' . $b`: a call of `sprintf()` becomes concatenation.
 *
 * Only for a target below PHP 8.4. There the compiler turns such a `sprintf()` into a rope itself,
 * and the rope is cheaper than the concatenation: `\sprintf('%s-%d', $s, $i)` saves 2 opcodes but
 * costs 4 % more CPU instructions and 8 % more time per call on PHP 8.5 (callgrind, melentev-av/opmin#66).
 * On PHP 8.1 the same rewrite costs 13 % fewer instructions.
 *
 * Fires only when the result is provably the same string:
 * - the format is a string literal of plain text, `%s`, `%d` and `%%` only — no flags, width,
 *   precision or positions (`%5s`, `%1$s`), no trailing `%`;
 * - there are exactly as many arguments as specifiers (fewer throw `ArgumentCountError`, extra ones
 *   would no longer be evaluated), positional, without spread;
 * - by native type every `%s` argument is a `string` or an `int` and every `%d` argument an `int`:
 *   for these `sprintf()` and `.` give the same text (a float is printed with `precision`, not
 *   `serialize_precision`; an object runs `__toString()` at another moment);
 * - an argument that is a local variable is read by `.` only when the concatenation reaches it, after
 *   the arguments right of it: none of them may write it (`sprintf('%s%s', $i, $i++)`).
 *
 * An argument that binds looser than `.` (`?:`, `??`, `=`) is reprinted with parentheses
 * ({@see Reprint}). A call that is an operand of `+`, `-`, `<<`, `>>` is left alone: php-parser would
 * print the `.` there without the parentheses PHP 8 needs.
 *
 * Not final: the fixtures run in subclasses with a fixed target (`tests/Rector/SprintfToConcatPhp83Rector.php`,
 * `SprintfToConcatPhp84Rector.php`) — the bridge runs Rector with the PHP of the tests as the target.
 *
 * @internal
 * @psalm-suppress ClassMustBeFinal
 */
class SprintfToConcatRector extends AbstractRector
{
    /** The first target where the compiler turns `sprintf()` into a rope itself. */
    private const int ROPE_SPRINTF = 80400;

    /** Attribute of an operand of `+`, `-`, `<<`, `>>`: a `.` there is printed without parentheses. */
    private const string IN_ARITHMETIC = 'opmin_in_arithmetic';

    public function __construct(
        private readonly PhpVersionProvider $phpVersionProvider,
    ) {}

    public static function alias(): string
    {
        return 'sprintf';
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Turn sprintf() with %s and %d of strings and ints into concatenation', [
            new ConfiguredCodeSample(
                "return \\sprintf('%s-%d', \$name, \$id);",
                "return \$name . '-' . \$id;",
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
        if ($this->phpTarget() >= self::ROPE_SPRINTF || $node->stmts === null || FunctionBody::ignored($node, self::alias())) {
            return null;
        }

        $referenced = FunctionBody::referencedVariables($node);
        $changed = false;
        $this->traverseNodesWithCallable($node->stmts, function (Node $n) use ($referenced, &$changed): int|Node|null {
            # Nested functions are refactored on their own, with their own ignore marks.
            if ($n instanceof Expr\Closure || $n instanceof Stmt\Function_ || $n instanceof Stmt\ClassLike) {
                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }

            # The parent comes first: a `.` under `+`, `-`, `<<`, `>>` would be printed without its parentheses.
            if ($n instanceof Expr\BinaryOp && Reprint::concatMisprintedUnder($n)) {
                $n->left->setAttribute(self::IN_ARITHMETIC, true);
                $n->right->setAttribute(self::IN_ARITHMETIC, true);
            }

            $concat = $n instanceof Expr\FuncCall ? $this->concat($n, $referenced) : null;
            $concat === null or $changed = true;

            return $concat;
        });

        return $changed ? $node : null;
    }

    /**
     * `php.target` as `80300` (`$config->phpVersion()` of the run).
     */
    protected function phpTarget(): int
    {
        return $this->phpVersionProvider->provide();
    }

    /**
     * The format split into literal text and specifiers, or null when it has anything but `%s`,
     * `%d` and `%%`.
     *
     * @return list<string|'s'|'d'>|null Text pieces as `"\0text"`, specifiers as `s`/`d`.
     */
    private static function parse(string $format): ?array
    {
        $parts = [];
        $text = '';
        for ($i = 0, $n = \strlen($format); $i < $n; ++$i) {
            if ($format[$i] !== '%') {
                $text .= $format[$i];
                continue;
            }

            $spec = $format[++$i] ?? '';
            if ($spec === '%') {
                $text .= '%';
                continue;
            }

            if ($spec !== 's' && $spec !== 'd') {
                return null;
            }

            $text === '' or $parts[] = "\0" . $text;
            $text = '';
            $parts[] = $spec;
        }

        $text === '' or $parts[] = "\0" . $text;

        return $parts;
    }

    private static function nativeType(Expr $expr): ?Type
    {
        /** @var mixed $scope */
        $scope = $expr->getAttribute(AttributeKey::SCOPE);

        return $scope instanceof Scope ? $scope->getNativeType($expr) : null;
    }

    /**
     * Whether evaluating `$expr` may write the local variable `$name`.
     *
     * @param array<string, true> $referenced
     */
    private static function mayWrite(Expr $expr, string $name, array $referenced): bool
    {
        $finder = new NodeFinder();
        foreach ($finder->findInstanceOf([$expr], Expr::class) as $node) {
            if ($node instanceof Expr\Assign || $node instanceof Expr\AssignOp || $node instanceof Expr\AssignRef
                || $node instanceof Expr\PreInc || $node instanceof Expr\PostInc || $node instanceof Expr\PreDec
                || $node instanceof Expr\PostDec || $node instanceof Expr\Eval_ || $node instanceof Expr\Include_
            ) {
                return true;
            }

            # A call may write a variable passed to it by reference, or one bound by reference anywhere.
            if ($node instanceof Expr\CallLike && (isset($referenced[$name])
                || $finder->findFirst($node->getRawArgs(), static fn(Node $v): bool => $v instanceof Expr\Variable && $v->name === $name) !== null)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, true> $referenced
     */
    private function concat(Expr\FuncCall $call, array $referenced): ?Expr
    {
        if (!GlobalFunctionCall::plain($call, 'sprintf') || $call->getArgs() === [] || $call->getAttribute(self::IN_ARITHMETIC) === true) {
            return null;
        }

        $args = \array_map(static fn(Node\Arg $a): Expr => $a->value, $call->getArgs());
        $format = \array_shift($args);
        if (!$format instanceof Scalar\String_) {
            return null;
        }

        $parts = self::parse($format->value);
        if ($parts === null || \count(\array_filter($parts, static fn(string $p): bool => $p[0] !== "\0")) !== \count($args)) {
            return null;
        }

        $stringOrInt = TypeCombinator::union(new StringType(), new IntegerType());
        # Text keeps the quotes of the format: a double-quoted one with escapes stays readable.
        $kind = $format->getAttribute('kind') === Scalar\String_::KIND_DOUBLE_QUOTED
            ? Scalar\String_::KIND_DOUBLE_QUOTED
            : Scalar\String_::KIND_SINGLE_QUOTED;
        $exprs = [];
        $next = 0;
        foreach ($parts as $part) {
            if ($part[0] === "\0") {
                $exprs[] = new Scalar\String_(\substr($part, 1), ['kind' => $kind]);
                continue;
            }

            $arg = $args[$next];
            $type = self::nativeType($arg);
            if ($type === null || !($part === 'd' ? new IntegerType() : $stringOrInt)->isSuperTypeOf($type)->yes()) {
                return null;
            }

            if ($arg instanceof Expr\Variable && \is_string($arg->name)) {
                foreach (\array_slice($args, $next + 1) as $later) {
                    if (self::mayWrite($later, $arg->name, $referenced)) {
                        return null;
                    }
                }
            }

            $exprs[] = $arg;
            ++$next;
        }

        if ($exprs === []) {
            return new Scalar\String_('');
        }

        # An argument printed as it was keeps no parentheses: one that binds looser than `.` — or,
        # alone, than anything — is reprinted, so the printer adds them.
        $limit = \count($exprs) === 1 ? Reprint::ANYWHERE : Reprint::LOOSER_THAN_CONCAT;
        foreach ($args as $arg) {
            if (!Reprint::fits($arg, $limit)) {
                return null;
            }
        }

        foreach ($args as $arg) {
            Reprint::prepare($arg, $limit);
        }

        if (\count($exprs) === 1) {
            $only = $exprs[0];
            $type = $only instanceof Scalar\String_ ? null : self::nativeType($only);

            return $type === null || $type->isString()->yes() ? $only : new Expr\Cast\String_($only);
        }

        $result = \array_shift($exprs);
        foreach ($exprs as $expr) {
            $result = new Expr\BinaryOp\Concat($result, $expr);
        }

        return $result;
    }
}
