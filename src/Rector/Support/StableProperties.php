<?php

declare(strict_types=1);

namespace Opmin\Rector\Support;

use Opmin\Module\Analysis\PropertyWrites;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Properties of a class that its own code may change after the object is initialized (plan, L1 of
 * the model of call effects): written outside `__construct()`, `__clone()`, `__wakeup()` and
 * `__unserialize()`, written there but not as `$this->p`, or exposed by reference anywhere (`&`,
 * a by-reference argument, `function &`, `unset()`). Closures and property hooks of the class
 * never initialize: they may run any time later.
 *
 * Only the code of the class is seen: for a private property it is all the code that may write it,
 * besides closures bound to the class, reflection and an explicit second constructor call — the
 * caller checks those for the whole project ({@see \Opmin\Module\Analysis\FileReferences}).
 *
 * @internal
 */
final class StableProperties
{
    /**
     * @param \Closure(Expr\CallLike, int): ?bool $byReference Whether the argument at the position is
     *        passed by reference; null — unknown.
     * @param \Closure(Expr): bool $surelyArray The value is never an object.
     * @return array<non-empty-string, true> Names of the properties that may change;
     *         {@see PropertyWrites::ANY} — any property.
     */
    public static function unstable(Stmt\ClassLike $class, \Closure $byReference, \Closure $surelyArray): array
    {
        $visitor = new class(new PropertyWrites($byReference, $surelyArray)) extends NodeVisitorAbstract {
            private const array INITIALIZERS = ['__construct' => true, '__clone' => true, '__wakeup' => true, '__unserialize' => true];

            /** @var array<non-empty-string, true> */
            public array $unstable = [];

            /** @var list<array{bool, bool}> Enclosing functions: [initializes `$this`, returns by reference]. */
            private array $functions = [];

            private int $nestedClasses = 0;

            public function __construct(
                private readonly PropertyWrites $writes,
            ) {}

            public function enterNode(Node $node): null
            {
                $node instanceof Stmt\ClassLike and ++$this->nestedClasses;
                $node instanceof Node\FunctionLike and $this->functions[] = [
                    $node instanceof Stmt\ClassMethod && $this->functions === [] && $this->nestedClasses === 0
                        && !$node->isStatic() && isset(self::INITIALIZERS[$node->name->toLowerString()]),
                    $node->returnsByRef(),
                ];
                # A promoted parameter by reference: the property is a reference to the argument.
                if ($node instanceof Node\Param && $node->byRef && $node->flags !== 0
                    && $node->var instanceof Expr\Variable && \is_string($node->var->name) && $node->var->name !== ''
                ) {
                    $this->unstable[$node->var->name] = true;
                }

                $function = $this->functions === [] ? [false, false] : $this->functions[\array_key_last($this->functions)];
                foreach ($this->writes->of($node, $function[1]) as $write) {
                    $write->reference || !$function[0] || !$write->viaThis and $this->unstable[$write->name] = true;
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                $node instanceof Stmt\ClassLike and --$this->nestedClasses;
                $node instanceof Node\FunctionLike and \array_pop($this->functions);

                return null;
            }
        };
        (new NodeTraverser($visitor))->traverse($class->stmts);

        return $visitor->unstable;
    }
}
