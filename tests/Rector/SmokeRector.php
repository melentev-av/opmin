<?php

declare(strict_types=1);

namespace Opmin\Tests\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * Trivial rule that keeps the `rector` suite honest until opmin's own rules exist:
 * renames `opmin_smoke_old()` calls to `opmin_smoke_new()`.
 */
#[TestRectorFixtures('Fixture/Smoke')]
final class SmokeRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Renames opmin_smoke_old() to opmin_smoke_new()', []);
    }

    public function getNodeTypes(): array
    {
        return [FuncCall::class];
    }

    /**
     * @param FuncCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if (!$this->isName($node, 'opmin_smoke_old')) {
            return null;
        }

        $node->name = new Name('opmin_smoke_new');

        return $node;
    }
}
