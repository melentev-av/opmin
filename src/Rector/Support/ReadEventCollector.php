<?php

declare(strict_types=1);

namespace Opmin\Rector\Support;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/**
 * Turns a statement list into {@see ReadEvent}s in evaluation order: the operands of an operation
 * before the operation, the left side of an assignment before the right one, a loop's stopping
 * events also before the loop (its body runs again after them).
 *
 * Over-approximates what may run user code (`IMPURE`) and what may change a value (`WRITE`,
 * `BASE_WRITE`): an argument passed to a function whose parameter is unknown counts as passed by
 * reference, an operator on a value that may be an object counts as a magic method call, an
 * unknown node is `OPAQUE`. Closures and nested declarations are not entered: their bodies are not
 * part of this function's evaluation.
 *
 * @internal
 */
final class ReadEventCollector
{
    private const MODE_READ = 0;
    private const MODE_WRITE = 1;
    private const MODE_ISSET = 2;
    private const MODE_OBJECT = 3;

    /** @var list<ReadEvent> */
    private array $events = [];

    private int $statement = 0;
    private bool $conditional = false;

    /**
     * @param \Closure(Expr): bool $neverObject The value of the expression is never an object (no
     *        magic `__toString()`, operator or comparison handler can run on it).
     * @param \Closure(Expr\PropertyFetch): bool $plainProperty Reading the property runs no user code.
     * @param \Closure(Expr\CallLike, int): ?bool $byReference Whether the argument at the position is
     *        passed by reference; null — unknown.
     */
    public function __construct(
        private readonly ReadSpec $spec,
        private readonly \Closure $neverObject,
        private readonly \Closure $plainProperty,
        private readonly \Closure $byReference,
    ) {}

    /**
     * @param array<array-key, Stmt> $stmts
     * @return list<ReadEvent>
     */
    public function collect(array $stmts): array
    {
        $this->events = [];
        foreach (\array_values($stmts) as $index => $stmt) {
            $this->statement = $index;
            $this->conditional = false;
            $this->stmt($stmt);
        }

        return $this->events;
    }

    /**
     * @param array<array-key, Stmt> $stmts
     */
    private function stmts(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            $this->stmt($stmt);
        }
    }

    private function stmt(Stmt $stmt): void
    {
        match (true) {
            $stmt instanceof Stmt\Expression => $this->expr($stmt->expr),
            $stmt instanceof Stmt\Return_ => $stmt->expr === null or $this->expr($stmt->expr),
            $stmt instanceof Stmt\Echo_ => $this->echo($stmt),
            $stmt instanceof Stmt\If_ => $this->if($stmt),
            $stmt instanceof Stmt\Switch_ => $this->switch($stmt),
            $stmt instanceof Stmt\Foreach_, $stmt instanceof Stmt\For_, $stmt instanceof Stmt\While_,
            $stmt instanceof Stmt\Do_ => $this->loop($stmt),
            $stmt instanceof Stmt\TryCatch => $this->try($stmt),
            $stmt instanceof Stmt\Unset_ => \array_map(fn(Expr $var) => $this->expr($var, self::MODE_WRITE), $stmt->vars),
            $stmt instanceof Stmt\Block => $this->stmts($stmt->stmts),
            $stmt instanceof Stmt\Nop, $stmt instanceof Stmt\Break_, $stmt instanceof Stmt\Continue_ => null,
            $stmt instanceof Stmt\InlineHTML => $this->emit(ReadEvent::IMPURE),
            default => $this->emit(ReadEvent::OPAQUE),
        };
    }

    private function echo(Stmt\Echo_ $stmt): void
    {
        foreach ($stmt->exprs as $expr) {
            $this->expr($expr);
            # Output runs the output buffer callbacks, and an object turns into a string.
            $this->emit(ReadEvent::IMPURE);
        }
    }

    private function if(Stmt\If_ $stmt): void
    {
        $this->expr($stmt->cond);
        $this->conditionally(function () use ($stmt): void {
            $this->stmts($stmt->stmts);
            foreach ($stmt->elseifs as $elseif) {
                $this->expr($elseif->cond);
                $this->stmts($elseif->stmts);
            }

            $stmt->else === null or $this->stmts($stmt->else->stmts);
        });
    }

    private function switch(Stmt\Switch_ $stmt): void
    {
        $this->expr($stmt->cond);
        $this->conditionally(function () use ($stmt): void {
            foreach ($stmt->cases as $case) {
                $case->cond === null or $this->expr($case->cond);
                $this->stmts($case->stmts);
            }
        });
    }

    private function try(Stmt\TryCatch $stmt): void
    {
        # An exception may leave the try block anywhere: the catch blocks are conditional.
        $this->conditionally(function () use ($stmt): void {
            $this->stmts($stmt->stmts);
            foreach ($stmt->catches as $catch) {
                $catch->var === null or $this->expr($catch->var, self::MODE_WRITE);
                $this->stmts($catch->stmts);
            }

            $stmt->finally === null or $this->stmts($stmt->finally->stmts);
        });
    }

    private function loop(Stmt\Foreach_|Stmt\For_|Stmt\While_|Stmt\Do_ $stmt): void
    {
        $outer = $this->events;
        $this->events = [];
        $this->conditionally(function () use ($stmt): void {
            if ($stmt instanceof Stmt\Foreach_) {
                $stmt->keyVar === null or $this->expr($stmt->keyVar, self::MODE_WRITE);
                $this->expr($stmt->valueVar, self::MODE_WRITE);
            } elseif ($stmt instanceof Stmt\For_) {
                \array_map(fn(Expr $e) => $this->expr($e), $stmt->cond);
                \array_map(fn(Expr $e) => $this->expr($e), $stmt->loop);
            } else {
                $this->expr($stmt->cond);
            }

            $this->stmts($stmt->stmts);
        });
        /** @var list<ReadEvent> $body */
        $body = $this->events;
        $this->events = $outer;

        # What runs once before the loop.
        if ($stmt instanceof Stmt\Foreach_) {
            # `foreach (… as &$v)` takes the iterated expression by reference.
            $this->expr($stmt->expr, $stmt->byRef ? self::MODE_WRITE : self::MODE_READ);
            # A Traversable object runs user code on every step.
            ($this->neverObject)($stmt->expr) or $this->emit(ReadEvent::IMPURE);
        } elseif ($stmt instanceof Stmt\For_) {
            \array_map(fn(Expr $e) => $this->expr($e), $stmt->init);
        }

        # The body runs again after its own writes and calls: they stop a region before the loop too.
        foreach ($body as $event) {
            $event->kind === ReadEvent::READ
                or $this->events[] = new ReadEvent($event->kind, $event->key, $event->base, $this->statement, true, $event->node);
        }

        \array_push($this->events, ...$body);
    }

    /**
     * @param \Closure(): void $body
     */
    private function conditionally(\Closure $body): void
    {
        $was = $this->conditional;
        $this->conditional = true;
        try {
            $body();
        } finally {
            $this->conditional = $was;
        }
    }

    private function expr(Expr $expr, int $mode = self::MODE_READ): void
    {
        if (($mode === self::MODE_READ || $mode === self::MODE_OBJECT) && ($match = $this->spec->match($expr)) !== null) {
            $this->emit(ReadEvent::READ, $match[0], $match[1], $expr);
            # Reading another property may run `__get()`, another array may be an ArrayAccess.
            $plain = $expr instanceof Expr\PropertyFetch ? ($this->plainProperty)($expr)
                : (!$expr instanceof Expr\ArrayDimFetch || ($this->neverObject)($expr->var));
            $plain or $this->emit(ReadEvent::IMPURE);
            return;
        }

        match (true) {
            $expr instanceof Expr\Variable => $this->variable($expr, $mode),
            $expr instanceof Expr\PropertyFetch => $this->propertyFetch($expr, $mode),
            $expr instanceof Expr\ArrayDimFetch => $this->dimFetch($expr, $mode),
            $mode === self::MODE_WRITE && ($expr instanceof Expr\List_ || $expr instanceof Expr\Array_) => $this->list($expr),
            $mode === self::MODE_WRITE => $this->emit(ReadEvent::OPAQUE),
            $expr instanceof Scalar, $expr instanceof Expr\ConstFetch => null,
            $expr instanceof Expr\Assign => $this->assign($expr),
            $expr instanceof Expr\AssignRef => $this->operands([$expr->var, $expr->expr], self::MODE_WRITE, ReadEvent::OPAQUE),
            $expr instanceof Expr\AssignOp => $this->assignOp($expr),
            $expr instanceof Expr\PreInc, $expr instanceof Expr\PreDec, $expr instanceof Expr\PostInc,
            $expr instanceof Expr\PostDec => $this->increment($expr),
            $expr instanceof Expr\BinaryOp\Coalesce => $this->coalesce($expr),
            $expr instanceof Expr\BinaryOp\BooleanAnd, $expr instanceof Expr\BinaryOp\BooleanOr,
            $expr instanceof Expr\BinaryOp\LogicalAnd, $expr instanceof Expr\BinaryOp\LogicalOr => $this->shortCircuit($expr),
            $expr instanceof Expr\BinaryOp => $this->binary($expr),
            $expr instanceof Expr\BooleanNot => $this->expr($expr->expr),
            $expr instanceof Expr\UnaryMinus, $expr instanceof Expr\UnaryPlus,
            $expr instanceof Expr\BitwiseNot => $this->typed($expr->expr),
            $expr instanceof Expr\Cast\Bool_, $expr instanceof Expr\Cast\Int_,
            $expr instanceof Expr\Cast\Double, $expr instanceof Expr\Cast\String_ => $this->typed($expr->expr),
            $expr instanceof Expr\Cast\Array_ => $this->expr($expr->expr),
            $expr instanceof Expr\Instanceof_ => $this->instanceOf($expr),
            $expr instanceof Expr\Ternary => $this->ternary($expr),
            $expr instanceof Expr\Match_ => $this->match($expr),
            $expr instanceof Expr\Isset_ => \array_map(fn(Expr $var) => $this->expr($var, self::MODE_ISSET), $expr->vars),
            $expr instanceof Expr\Empty_ => $this->expr($expr->expr, self::MODE_ISSET),
            $expr instanceof Expr\Array_ => $this->array($expr),
            $expr instanceof Expr\ClassConstFetch => $this->classConst($expr),
            $expr instanceof Scalar\InterpolatedString => $this->interpolated($expr),
            $expr instanceof Expr\Closure => $this->closure($expr),
            $expr instanceof Expr\ArrowFunction => null,
            $expr instanceof Expr\FuncCall, $expr instanceof Expr\MethodCall, $expr instanceof Expr\NullsafeMethodCall,
            $expr instanceof Expr\StaticCall, $expr instanceof Expr\New_ => $this->call($expr),
            $expr instanceof Expr\NullsafePropertyFetch => $this->operands([$expr->var], self::MODE_READ, ReadEvent::IMPURE),
            $expr instanceof Expr\Clone_, $expr instanceof Expr\Print_, $expr instanceof Expr\Throw_,
            $expr instanceof Expr\Exit_, $expr instanceof Expr\Yield_, $expr instanceof Expr\YieldFrom,
            $expr instanceof Expr\StaticPropertyFetch, $expr instanceof Expr\ErrorSuppress => $this->impureChildren($expr),
            default => $this->emit(ReadEvent::OPAQUE),
        };
    }

    private function variable(Expr\Variable $expr, int $mode): void
    {
        if (!\is_string($expr->name)) {
            $this->emit(ReadEvent::OPAQUE);
            return;
        }

        $mode === self::MODE_WRITE and $this->emit(ReadEvent::BASE_WRITE, null, $expr->name);
    }

    private function propertyFetch(Expr\PropertyFetch $expr, int $mode): void
    {
        $match = $this->spec->match($expr);
        if ($mode === self::MODE_WRITE) {
            if ($match !== null) {
                $this->emit(ReadEvent::WRITE, $match[0], $match[1], $expr);
                # A hook or `__set()` of the written property runs user code.
                ($this->plainProperty)($expr) or $this->emit(ReadEvent::IMPURE);
                return;
            }

            # `$x->$name = …` may write any property of `$x`.
            if (!$expr->name instanceof Node\Identifier && $expr->var instanceof Expr\Variable && \is_string($expr->var->name)) {
                $this->emit(ReadEvent::WRITE, null, $expr->var->name, $expr);
                return;
            }

            # An object is changed in place: its variable keeps the same object.
            $this->expr($expr->var, self::MODE_OBJECT);
            $expr->name instanceof Expr and $this->expr($expr->name);
            # `__set()` of another object, a property hook.
            $this->emit(ReadEvent::IMPURE);
            return;
        }

        $this->expr($expr->var, $mode === self::MODE_OBJECT ? self::MODE_OBJECT : self::MODE_READ);
        $expr->name instanceof Expr and $this->expr($expr->name);
        if ($mode === self::MODE_ISSET || !$expr->name instanceof Node\Identifier || !($this->plainProperty)($expr)) {
            # `__isset()`, `__get()`, a hook, an unknown property.
            $this->emit(ReadEvent::IMPURE);
        }
    }

    private function dimFetch(Expr\ArrayDimFetch $expr, int $mode): void
    {
        # `$a['k'] = …` changes the array `$a` itself; `$o->list[] = …` fetches `$o->list` for writing.
        $this->expr($expr->var, match ($mode) {
            self::MODE_READ, self::MODE_OBJECT => self::MODE_READ,
            self::MODE_ISSET => self::MODE_ISSET,
            default => self::MODE_WRITE,
        });
        $expr->dim === null or $this->expr($expr->dim);
        # `offsetGet()`/`offsetSet()` of an ArrayAccess object.
        ($this->neverObject)($expr->var) or $this->emit(ReadEvent::IMPURE);
    }

    private function list(Expr\List_|Expr\Array_ $expr): void
    {
        foreach ($expr->items as $item) {
            if ($item === null) {
                continue;
            }

            $item->key === null or $this->expr($item->key);
            $item->byRef ? $this->emit(ReadEvent::OPAQUE) : $this->expr($item->value, self::MODE_WRITE);
        }
    }

    private function assign(Expr\Assign $expr): void
    {
        if ($expr->var instanceof Expr\Variable || $expr->var instanceof Expr\List_ || $expr->var instanceof Expr\Array_) {
            # `$v = expr`: the value is computed first; a list is destructured after it.
            $this->expr($expr->expr);
            $this->expr($expr->var, self::MODE_WRITE);
            # Assigning an object to a variable may destroy its previous object: `__destruct()`.
            ($this->neverObject)($expr->var) or $this->emit(ReadEvent::IMPURE);
            return;
        }

        # `$a[f()] = g()`: the dimensions and objects of the left side are evaluated first.
        $this->expr($expr->var, self::MODE_WRITE);
        $this->expr($expr->expr);
    }

    private function assignOp(Expr\AssignOp $expr): void
    {
        $this->expr($expr->var, self::MODE_WRITE);
        $this->expr($expr->expr);
        ($this->neverObject)($expr->var) && ($this->neverObject)($expr->expr) or $this->emit(ReadEvent::IMPURE);
    }

    private function increment(Expr\PreInc|Expr\PreDec|Expr\PostInc|Expr\PostDec $expr): void
    {
        $this->expr($expr->var, self::MODE_WRITE);
        ($this->neverObject)($expr->var) or $this->emit(ReadEvent::IMPURE);
    }

    private function coalesce(Expr\BinaryOp\Coalesce $expr): void
    {
        $this->expr($expr->left, self::MODE_ISSET);
        $this->conditionally(fn() => $this->expr($expr->right));
    }

    private function shortCircuit(Expr\BinaryOp $expr): void
    {
        $this->expr($expr->left);
        $this->conditionally(fn() => $this->expr($expr->right));
    }

    private function binary(Expr\BinaryOp $expr): void
    {
        $this->expr($expr->left);
        $this->expr($expr->right);
        # Strict comparisons never call user code; every other operator may on an object.
        $strict = $expr instanceof Expr\BinaryOp\Identical || $expr instanceof Expr\BinaryOp\NotIdentical;
        $strict || (($this->neverObject)($expr->left) && ($this->neverObject)($expr->right)) or $this->emit(ReadEvent::IMPURE);
    }

    private function typed(Expr $operand): void
    {
        $this->expr($operand);
        ($this->neverObject)($operand) or $this->emit(ReadEvent::IMPURE);
    }

    private function instanceOf(Expr\Instanceof_ $expr): void
    {
        $this->expr($expr->expr);
        $expr->class instanceof Expr and $this->expr($expr->class);
    }

    private function ternary(Expr\Ternary $expr): void
    {
        $this->expr($expr->cond);
        $this->conditionally(function () use ($expr): void {
            $expr->if === null or $this->expr($expr->if);
            $this->expr($expr->else);
        });
    }

    private function match(Expr\Match_ $expr): void
    {
        $this->expr($expr->cond);
        $this->conditionally(function () use ($expr): void {
            foreach ($expr->arms as $arm) {
                \array_map(fn(Expr $c) => $this->expr($c), $arm->conds ?? []);
                $this->expr($arm->body);
            }
        });
        # No matching arm throws `UnhandledMatchError`.
    }

    private function array(Expr\Array_ $expr): void
    {
        foreach ($expr->items as $item) {
            if ($item === null) {
                continue;
            }

            $item->key === null or $this->expr($item->key);
            if ($item->byRef) {
                $this->expr($item->value, self::MODE_WRITE);
                $this->emit(ReadEvent::OPAQUE);
                continue;
            }

            $this->expr($item->value);
            # Unpacking a Traversable runs its iterator.
            $item->unpack && !($this->neverObject)($item->value) and $this->emit(ReadEvent::IMPURE);
        }
    }

    private function classConst(Expr\ClassConstFetch $expr): void
    {
        if ($expr->class instanceof Expr) {
            $this->expr($expr->class);
            $this->emit(ReadEvent::IMPURE);
            return;
        }

        $class = $expr->class->toLowerString();
        $own = \in_array($class, ['self', 'static', 'parent'], true)
            || ($expr->name instanceof Node\Identifier && $expr->name->toLowerString() === 'class');
        # Another class may be autoloaded here.
        $own or $this->emit(ReadEvent::IMPURE);
    }

    private function interpolated(Scalar\InterpolatedString $expr): void
    {
        foreach ($expr->parts as $part) {
            $part instanceof Expr and $this->typed($part);
        }
    }

    private function closure(Expr\Closure $expr): void
    {
        foreach ($expr->uses as $use) {
            # A variable captured by reference may change whenever the closure runs.
            $use->byRef and $this->emit(ReadEvent::BASE_WRITE, null, \is_string($use->var->name) ? $use->var->name : null);
        }
    }

    private function call(Expr\FuncCall|Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall|Expr\New_ $call): void
    {
        if ($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall) {
            $this->expr($call->var, self::MODE_OBJECT);
            $call->name instanceof Expr and $this->expr($call->name);
        } elseif ($call instanceof Expr\StaticCall || $call instanceof Expr\New_) {
            $call->class instanceof Expr and $this->expr($call->class);
            $call instanceof Expr\StaticCall && $call->name instanceof Expr and $this->expr($call->name);
        } elseif ($call->name instanceof Expr) {
            $this->expr($call->name);
        }

        if ($call->isFirstClassCallable()) {
            return;
        }

        foreach (\array_values($call->getArgs()) as $position => $arg) {
            $byRef = $arg->unpack || $arg->name !== null ? null : ($this->byReference)($call, $position);
            if ($byRef === false || !$this->referable($arg->value)) {
                $this->expr($arg->value);
                $arg->unpack && !($this->neverObject)($arg->value) and $this->emit(ReadEvent::IMPURE);
                continue;
            }

            # Maybe passed by reference: the callee may write it.
            $this->expr($arg->value, self::MODE_WRITE);
        }

        $this->emit(ReadEvent::IMPURE);
    }

    private function referable(Expr $expr): bool
    {
        return $expr instanceof Expr\Variable || $expr instanceof Expr\PropertyFetch
            || $expr instanceof Expr\ArrayDimFetch || $expr instanceof Expr\StaticPropertyFetch;
    }

    /**
     * @param list<Expr> $operands
     * @param ReadEvent::* $after
     */
    private function operands(array $operands, int $mode, string $after): void
    {
        foreach ($operands as $operand) {
            $this->expr($operand, $mode);
        }

        $this->emit($after);
    }

    private function impureChildren(Expr $expr): void
    {
        foreach ($expr->getSubNodeNames() as $name) {
            /** @var mixed $child */
            $child = $expr->{$name};
            $child instanceof Expr and $this->expr($child);
        }

        $this->emit(ReadEvent::IMPURE);
    }

    /**
     * @param ReadEvent::* $kind
     */
    private function emit(string $kind, ?string $key = null, ?string $base = null, ?Expr $node = null): void
    {
        $this->events[] = new ReadEvent(
            $kind,
            $key === '' ? null : $key,
            $base === '' ? null : $base,
            $this->statement,
            $this->conditional,
            $node,
        );
    }
}
