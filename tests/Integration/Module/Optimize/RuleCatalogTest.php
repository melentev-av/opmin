<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Module\Optimize;

use Opmin\Module\Config\Schema;
use Opmin\Module\Optimize\Rector\RuleCatalog;
use Opmin\Module\Optimize\Rector\RuleSpec;
use Opmin\Rector\Rule\ExtractRepeatedPropertyFetchRector;
use Opmin\Rector\Rule\FullyQualifyGlobalCallsRector;
use Opmin\Rector\Rule\HoistLoopInvariantCountRector;
use Opmin\Rector\Rule\RemoveUnusedClosureUseRector;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(RuleCatalog::class)]
#[Covers(RuleSpec::class)]
final class RuleCatalogTest
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unknownOnly(): iterable
    {
        yield 'alias with a typo' => [
            'fqm',
            'Unknown Rector rule `fqm` in --rector-rule. Did you mean `fqn`? Name a rule by its alias (fqn, property_fetch, array_dim_fetch, count, closure_use, sprintf, array_push,'
            . ' str_starts_with, in_array, nullsafe, if_to_match, switch_to_match), the short class name of a rule of the run or the full class name.',
        ];
        yield 'short name with a typo' => [
            'HoistLoopInvariantCountRectr',
            'Unknown Rector rule `HoistLoopInvariantCountRectr` in --rector-rule. Did you mean `HoistLoopInvariantCountRector`?',
        ];
        yield 'full name with a typo' => [
            'Opmin\Rector\Rule\FullyQualifyGlobalCallRector',
            'Unknown Rector rule `Opmin\Rector\Rule\FullyQualifyGlobalCallRector` in --rector-rule: check the class name against the installed Rector.'
            . ' Did you mean `Opmin\Rector\Rule\FullyQualifyGlobalCallsRector`?',
        ];
        yield 'full name in another namespace' => [
            '\App\HoistLoopInvariantCountRector',
            'Unknown Rector rule `App\HoistLoopInvariantCountRector` in --rector-rule: check the class name against the installed Rector.'
            . ' Did you mean `Opmin\Rector\Rule\HoistLoopInvariantCountRector`?',
        ];
        yield 'nothing close' => ['App\NoSuchRector', 'Unknown Rector rule `App\NoSuchRector` in --rector-rule: check the class name against the installed Rector.'];
    }

    public function ownRulesOnlyByDefault(): void
    {
        $rules = RuleCatalog::build(new Schema\Rector(), new Schema\RectorStandard(), null, [], '8.3');

        Assert::same(\array_map(static fn(RuleSpec $r): string => $r->shortName(), $rules), [
            'FullyQualifyGlobalCallsRector',
            'ExtractRepeatedPropertyFetchRector',
            'ExtractRepeatedArrayDimFetchRector',
            'HoistLoopInvariantCountRector',
            'RemoveUnusedClosureUseRector',
            'SprintfToConcatRector',
            'ArrayPushToAssignRector',
            'StrStartsWithRector',
            'OrIdenticalToInArrayRector',
            'NullCheckTernaryToNullsafeRector',
            'IfChainToMatchRector',
            'SwitchToMatchRector',
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

        Assert::same(\count($rules), \count((new Schema\Rector())->customRules) - 1);
    }

    public function standardSetsExpandIntoSingleRulesAfterOwnOnes(): void
    {
        $standard = new Schema\RectorStandard();
        $standard->sets = ['DEAD_CODE'];
        $standard->rules = ['Rector\CodeQuality\Rector\If_\SimplifyIfReturnBoolRector'];
        $standard->skip = ['Rector\DeadCode\Rector\Foreach_\RemoveUnusedForeachKeyRector'];

        $rules = RuleCatalog::build(new Schema\Rector(), $standard, true, [], '8.3');
        $names = \array_map(static fn(RuleSpec $r): string => $r->class, $rules);

        Assert::same(\array_slice($names, 0, 5), [
            FullyQualifyGlobalCallsRector::class,
            ExtractRepeatedPropertyFetchRector::class,
            'Opmin\Rector\Rule\ExtractRepeatedArrayDimFetchRector',
            HoistLoopInvariantCountRector::class,
            RemoveUnusedClosureUseRector::class,
        ]);
        Assert::true(\in_array('Rector\DeadCode\Rector\Cast\RecastingRemovalRector', $names, true));
        Assert::false(\in_array('Rector\DeadCode\Rector\Foreach_\RemoveUnusedForeachKeyRector', $names, true));
        Assert::same(\end($names), 'Rector\CodeQuality\Rector\If_\SimplifyIfReturnBoolRector');
        $fromSet = $rules[\count((new Schema\Rector())->customRules)];
        Assert::string((string) $fromSet->set)->endsWith('dead-code.php');
        Assert::false(\in_array($fromSet->class, $fromSet->skip, true));
        Assert::true(\count($fromSet->skip) > 3);
    }

    public function enabledInConfigButSwitchedOffByTheFlag(): void
    {
        $standard = new Schema\RectorStandard();
        $standard->enabled = true;

        Assert::same(\count(RuleCatalog::build(new Schema\Rector(), $standard, false, [], '8.3')), \count((new Schema\Rector())->customRules));
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

    public function ignoreNamesAreTheAliasesAndShortNamesOfOwnRulesAndTheRun(): void
    {
        $config = new Schema\Rector();
        $config->customRules[HoistLoopInvariantCountRector::class] = false;
        $rules = [new RuleSpec(FullyQualifyGlobalCallsRector::class), new RuleSpec('Rector\CodeQuality\Rector\If_\SimplifyIfReturnBoolRector')];

        [$aliases, $short] = RuleCatalog::ignoreNames($config, $rules);

        Assert::same($aliases, ['fqn', 'property_fetch', 'array_dim_fetch', 'count', 'closure_use', 'sprintf', 'array_push', 'str_starts_with', 'in_array', 'nullsafe', 'if_to_match', 'switch_to_match', 'llm']);
        Assert::same($short, [
            'FullyQualifyGlobalCallsRector',
            'SimplifyIfReturnBoolRector',
            'ExtractRepeatedPropertyFetchRector',
            'ExtractRepeatedArrayDimFetchRector',
            'HoistLoopInvariantCountRector',
            'RemoveUnusedClosureUseRector',
            'SprintfToConcatRector',
            'ArrayPushToAssignRector',
            'StrStartsWithRector',
            'OrIdenticalToInArrayRector',
            'NullCheckTernaryToNullsafeRector',
            'IfChainToMatchRector',
            'SwitchToMatchRector',
        ]);
    }

    public function onlyTakesAliasesAndShortNames(): void
    {
        $config = new Schema\Rector();
        $config->customRules[HoistLoopInvariantCountRector::class] = false;

        $rules = RuleCatalog::build($config, new Schema\RectorStandard(), null, ['fqn', 'COUNT', 'ExtractRepeatedPropertyFetchRector'], '8.3');

        Assert::same(\array_map(static fn(RuleSpec $r): string => $r->class, $rules), [
            FullyQualifyGlobalCallsRector::class,
            HoistLoopInvariantCountRector::class,
            ExtractRepeatedPropertyFetchRector::class,
        ]);
        Assert::same($rules[2]->options, ['min_reads' => 'auto']);
    }

    #[DataProvider('unknownOnly')]
    public function unknownOnlyNamesTheSourceAndTheClosestRule(string $name, string $message): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining($message);

        RuleCatalog::build(new Schema\Rector(), new Schema\RectorStandard(), null, [$name], '8.3');
    }

    public function unknownOwnRuleNamesTheClosestOne(): never
    {
        $config = new Schema\Rector();
        $config->customRules['Opmin\Rector\Rule\HoistLoopInvariantCountRectr'] = [];
        Expect::exception(\InvalidArgumentException::class)->withMessage(
            'Unknown Rector rule `Opmin\Rector\Rule\HoistLoopInvariantCountRectr` in rector.custom_rules: check the class name against the installed Rector.'
            . ' Did you mean `Opmin\Rector\Rule\HoistLoopInvariantCountRector`?',
        );

        RuleCatalog::build($config, new Schema\RectorStandard(), null, [], '8.3');
    }

    public function upToPhpTargetNeedsTheTarget(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('needs php.target');

        RuleCatalog::setFile('UP_TO_PHP_TARGET', null);
    }
}
