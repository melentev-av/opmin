<?php

declare(strict_types=1);

namespace Opmin\Tests\Rector;

use Opmin\Rector\Support\PureFunctionCall;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Type;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Rector\Reflection\ReflectionResolver;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * The classification table of {@see PureFunctionCall}: a statement `f(...);` becomes `$pure = f(...);`
 * when the call counts as pure, and stays as it is otherwise.
 */
#[TestRectorFixtures('Fixture/PureFunctionCall')]
final class PureFunctionCallProbeRector extends AbstractRector
{
    public function __construct(
        private readonly ReflectionResolver $reflectionResolver,
    ) {}

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Marks the pure calls of built-in functions', []);
    }

    public function getNodeTypes(): array
    {
        return [Stmt\Expression::class];
    }

    /**
     * @param Stmt\Expression $node
     */
    public function refactor(Node $node): ?Node
    {
        $call = $node->expr;
        if (!$call instanceof Expr\FuncCall) {
            return null;
        }

        $nativeType = static function (Expr $expr): ?Type {
            /** @var mixed $scope */
            $scope = $expr->getAttribute(AttributeKey::SCOPE);

            return $scope instanceof Scope ? $scope->getNativeType($expr) : null;
        };
        if (!(new PureFunctionCall($this->reflectionResolver, $nativeType))->pure($call)) {
            return null;
        }

        $node->expr = new Expr\Assign(new Expr\Variable('pure'), $call);

        return $node;
    }
}
