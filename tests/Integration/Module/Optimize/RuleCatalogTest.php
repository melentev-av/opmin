<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Module\Optimize;

use Opmin\Module\Config\Schema;
use Opmin\Module\Optimize\Rector\RuleCatalog;
use Opmin\Module\Optimize\Rector\RuleSpec;
use Opmin\Rector\Rule\ExtractRepeatedPropertyFetchRector;
use Opmin\Rector\Rule\FullyQualifyGlobalCallsRector;
use Opmin\Rector\Rule\HoistLoopInvariantCountRector;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(RuleCatalog::class)]
#[Covers(RuleSpec::class)]
final class RuleCatalogTest
{
    public function ownRulesOnlyByDefault(): void
    {
        $rules = RuleCatalog::build(new Schema\Rector(), new Schema\RectorStandard(), null, [], '8.3');

        Assert::same(\array_map(static fn(RuleSpec $r): string => $r->shortName(), $rules), [
            'FullyQualifyGlobalCallsRector',
            'ExtractRepeatedPropertyFetchRector',
            'ExtractRepeatedArrayDimFetchRector',
            'HoistLoopInvariantCountRector',
        ]);
        Assert::same($rules[0]->options, []);
        Assert::same($rules[1]->options, ['min_reads' => 'auto']);
        Assert::same($rules[3]->options, null);
        Assert::true($rules[3]->executedGain);
        Assert::false($rules[0]->executedGain);
        Assert::same($rules[0]->alias(), 'fqn');
        Assert::true($rules[0]->custom);
    }

    public function aRuleIsDisabledWithFalse(): void
    {
        $config = new Schema\Rector();
        $config->customRules[FullyQualifyGlobalCallsRector::class] = false;

        $rules = RuleCatalog::build($config, new Schema\RectorStandard(), null, [], '8.3');

        Assert::same(\count($rules), 3);
    }

    public function standardSetsExpandIntoSingleRulesAfterOwnOnes(): void
    {
        $standard = new Schema\RectorStandard();
        $standard->sets = ['DEAD_CODE'];
        $standard->rules = ['Rector\CodeQuality\Rector\If_\SimplifyIfReturnBoolRector'];
        $standard->skip = ['Rector\DeadCode\Rector\Foreach_\RemoveUnusedForeachKeyRector'];

        $rules = RuleCatalog::build(new Schema\Rector(), $standard, true, [], '8.3');
        $names = \array_map(static fn(RuleSpec $r): string => $r->class, $rules);

        Assert::same(\array_slice($names, 0, 4), [
            FullyQualifyGlobalCallsRector::class,
            ExtractRepeatedPropertyFetchRector::class,
            'Opmin\Rector\Rule\ExtractRepeatedArrayDimFetchRector',
            HoistLoopInvariantCountRector::class,
        ]);
        Assert::true(\in_array('Rector\DeadCode\Rector\Cast\RecastingRemovalRector', $names, true));
        Assert::false(\in_array('Rector\DeadCode\Rector\Foreach_\RemoveUnusedForeachKeyRector', $names, true));
        Assert::same(\end($names), 'Rector\CodeQuality\Rector\If_\SimplifyIfReturnBoolRector');
        $fromSet = $rules[4];
        Assert::string((string) $fromSet->set)->endsWith('dead-code.php');
        Assert::false(\in_array($fromSet->class, $fromSet->skip, true));
        Assert::true(\count($fromSet->skip) > 3);
    }

    public function enabledInConfigButSwitchedOffByTheFlag(): void
    {
        $standard = new Schema\RectorStandard();
        $standard->enabled = true;

        Assert::same(\count(RuleCatalog::build(new Schema\Rector(), $standard, false, [], '8.3')), 4);
    }

    public function onlyTheGivenRules(): void
    {
        $rules = RuleCatalog::build(new Schema\Rector(), new Schema\RectorStandard(), null, [
            '\\' . HoistLoopInvariantCountRector::class,
            'Rector\CodeQuality\Rector\If_\SimplifyIfReturnBoolRector',
        ], '8.3');

        Assert::same(\array_map(static fn(RuleSpec $r): string => $r->shortName(), $rules), ['HoistLoopInvariantCountRector', 'SimplifyIfReturnBoolRector']);
    }

    public function upToPhpTargetFollowsTheTarget(): void
    {
        Assert::string(RuleCatalog::setFile('UP_TO_PHP_TARGET', '8.1'))->endsWith('up-to-php81.php');
        Assert::string(RuleCatalog::setFile('DEAD_CODE', null))->endsWith('dead-code.php');
    }

    public function unknownSetFails(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Unknown Rector set `NO_SUCH_SET` in rector.standard.sets.');

        RuleCatalog::setFile('NO_SUCH_SET', '8.3');
    }

    public function unknownRuleFails(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Unknown Rector rule `App\NoSuchRector`');

        RuleCatalog::build(new Schema\Rector(), new Schema\RectorStandard(), null, ['App\NoSuchRector'], '8.3');
    }

    public function upToPhpTargetNeedsTheTarget(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('needs php.target');

        RuleCatalog::setFile('UP_TO_PHP_TARGET', null);
    }
}
