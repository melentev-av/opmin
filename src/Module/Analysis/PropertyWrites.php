<?php

declare(strict_types=1);

namespace Opmin\Module\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * The properties one node writes or lets be written later through a reference — the raw facts
 * behind stable properties (a property only constructors write, which no call can change).
 *
 * Over-approximates: an argument whose passing is unknown counts as passed by reference, a
 * property of a dynamic name is any property ({@see self::ANY}), and so is every property of an
 * object iterated by reference (`foreach ($object as &$v)`). Walking an object like an array
 * (`array_walk()`, `new ArrayObject($object)`) writes any property of any class whatever the scope:
 * {@see self::walksObject()}.
 *
 * @internal
 */
final class PropertyWrites
{
    /** Name of a property that may be any property. */
    public const ANY = '*';

    /** Functions taking an array or an object whose properties they may write. */
    private const array WALKERS = ['array_walk' => 0, 'array_walk_recursive' => 0];

    /** Classes taking an array or an object whose properties they may write (`offsetSet()`). */
    private const array WALKER_CLASSES = ['arrayobject', 'arrayiterator', 'recursivearrayiterator'];

    /**
     * @param \Closure(Expr\CallLike, int): ?bool $byReference Whether the argument at the position is
     *        passed by reference; null — unknown.
     * @param \Closure(Expr): bool $surelyArray The value is never an object.
     */
    public function __construct(
        private readonly \Closure $byReference,
        private readonly \Closure $surelyArray,
    ) {}

    /**
     * @param bool $returnsByReference The node is inside a function that returns by reference (`function &f()`).
     * @return list<PropertyWrite>
     */
    public function of(Node $node, bool $returnsByReference): array
    {
        $writes = [];
        match (true) {
            $node instanceof Expr\Assign, $node instanceof Expr\AssignOp => $this->target($node->var, false, $writes),
            $node instanceof Expr\AssignRef => [$this->target($node->var, true, $writes), $this->target($node->expr, true, $writes)],
            $node instanceof Expr\PreInc, $node instanceof Expr\PreDec, $node instanceof Expr\PostInc,
            $node instanceof Expr\PostDec => $this->target($node->var, false, $writes),
            $node instanceof Stmt\Unset_ => $this->unset($node, $writes),
            $node instanceof Stmt\Foreach_ => $this->foreach($node, $writes),
            $node instanceof Expr\Array_ => $this->arrayItems($node, $writes),
            $node instanceof Expr\FuncCall, $node instanceof Expr\MethodCall, $node instanceof Expr\NullsafeMethodCall,
            $node instanceof Expr\StaticCall, $node instanceof Expr\New_ => $this->call($node, $writes),
            $returnsByReference && $node instanceof Stmt\Return_ && $node->expr !== null => $this->target($node->expr, true, $writes),
            $returnsByReference && $node instanceof Expr\Yield_ && $node->value !== null => $this->target($node->value, true, $writes),
            $node instanceof Expr\ArrowFunction && $node->byRef => $this->target($node->expr, true, $writes),
            default => null,
        };

        return $writes;
    }

    /**
     * `array_walk()`, `new ArrayObject()` of what may be an object: they write its properties as
     * array elements, private ones too, whatever the scope of the call.
     */
    public function walksObject(Node $node): bool
    {
        $first = $node instanceof Expr\CallLike && !$node->isFirstClassCallable() ? ($node->getArgs()[0]->value ?? null) : null;
        if ($first === null || ($this->surelyArray)($first)) {
            return false;
        }

        return match (true) {
            $node instanceof Expr\FuncCall => ($name = DynamicScopeDetector::globalFunction($node->name)) !== null && isset(self::WALKERS[$name]),
            $node instanceof Expr\New_ => $node->class instanceof Node\Name
                && \in_array(\strtolower(\ltrim($node->class->toString(), '\\')), self::WALKER_CLASSES, true),
            default => false,
        };
    }

    /**
     * @param list<PropertyWrite> $writes
     */
    private function target(Expr $expr, bool $reference, array &$writes): void
    {
        if ($expr instanceof Expr\List_ || ($expr instanceof Expr\Array_ && !$reference)) {
            foreach ($expr->items as $item) {
                $item === null or $this->target($item->value, $reference || $item->byRef, $writes);
            }

            return;
        }

        # `$this->p['k'] = …` changes `p`; `$this->p->q = …` changes the object in `p`, not `p`.
        while ($expr instanceof Expr\ArrayDimFetch) {
            $expr = $expr->var;
        }

        if (!$expr instanceof Expr\PropertyFetch) {
            return;
        }

        $writes[] = new PropertyWrite(
            $expr->name instanceof Node\Identifier ? $expr->name->toString() : self::ANY,
            $expr->var instanceof Expr\Variable && $expr->var->name === 'this',
            $reference,
        );
    }

    /**
     * @param list<PropertyWrite> $writes
     */
    private function unset(Stmt\Unset_ $node, array &$writes): void
    {
        foreach ($node->vars as $var) {
            # Unsetting a property itself makes the next read reach `__get()`: never stable.
            $this->target($var, $var instanceof Expr\PropertyFetch, $writes);
        }
    }

    /**
     * @param list<PropertyWrite> $writes
     */
    private function foreach(Stmt\Foreach_ $node, array &$writes): void
    {
        $node->keyVar === null or $this->target($node->keyVar, false, $writes);
        $this->target($node->valueVar, $node->byRef, $writes);
        if (!$node->byRef) {
            return;
        }

        $this->target($node->expr, true, $writes);
        $this->walked($node->expr, $writes);
    }

    /**
     * @param list<PropertyWrite> $writes
     */
    private function arrayItems(Expr\Array_ $node, array &$writes): void
    {
        foreach ($node->items as $item) {
            $item !== null && $item->byRef and $this->target($item->value, true, $writes);
        }
    }

    /**
     * @param list<PropertyWrite> $writes
     */
    private function call(Expr\FuncCall|Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall|Expr\New_ $call, array &$writes): void
    {
        if ($call->isFirstClassCallable()) {
            return;
        }

        foreach (\array_values($call->getArgs()) as $position => $arg) {
            $byReference = $arg->unpack || $arg->name !== null ? null : ($this->byReference)($call, $position);
            $byReference === false or $this->target($arg->value, true, $writes);
        }
    }

    /**
     * `foreach ($object as &$v)` exposes every property visible in the scope.
     *
     * @param list<PropertyWrite> $writes
     */
    private function walked(Expr $expr, array &$writes): void
    {
        ($this->surelyArray)($expr) or $writes[] = new PropertyWrite(self::ANY, false, true);
    }
}
