<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Config;

use Opmin\Command\SchemaDump;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;
use Opmin\Module\Config\ConfigSchema;
use Opmin\Module\Config\JsonSchemaGenerator;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ConfigSchema::class)]
#[Covers(JsonSchemaGenerator::class)]
final class ConfigSchemaTest
{
    public function everyConfigClassIsRegistered(): void
    {
        $declared = [];
        foreach (\glob(\dirname(__DIR__, 4) . '/src/Module/Config/Schema/*.php') ?: [] as $file) {
            $class = 'Opmin\\Module\\Config\\Schema\\' . \basename($file, '.php');
            (new \ReflectionClass($class))->getAttributes(InflectableConfig::class) === [] or $declared[] = $class;
        }

        Assert::array(ConfigSchema::SECTIONS)->sameElementsAs($declared);
    }

    public function containsEveryKeyOfTheBrief(): void
    {
        Assert::array(ConfigSchema::keys())->hasKeys(
            'paths',
            'exclude',
            'php.binary',
            'php.target',
            'tests.runner',
            'tests.command',
            'commands.phpstan',
            'commands.format',
            'rector.custom_rules',
            'rector.standard.enabled',
            'rector.standard.sets',
            'rector.standard.rules',
            'rector.standard.skip',
            'rector.max_passes',
            'verification.warnings',
            'verification.min_branch_coverage',
            'verification.coverage_driver',
            'verification.random_inputs',
            'verification.fuzz_time_ms',
            'verification.seed',
            'verification.float_tolerance',
            'verification.call_timeout_ms',
            'verification.memory_limit',
            'verification.allow_unverified',
            'readability.min_gain',
            'readability.min_gain_per_line',
            'readability.max_cyclomatic_increase',
            'readability.max_nesting_increase',
            'readability.forbid_patterns',
            'signatures.phpdoc',
            'signatures.native_types',
            'signatures.public_api',
            'ignore.paths',
            'ignore.functions',
            'llm.top_n',
            'llm.attempts_per_function',
            'guard_perf.enabled',
            'guard_perf.max_regression_percent',
            'check.tolerance',
            'check.max_ops_new_function',
            'check.base_ref',
            'cache.dir',
            'cache.driver',
        );
    }

    public function sectionsAreDetected(): void
    {
        Assert::true(ConfigSchema::isSection('rector'));
        Assert::true(ConfigSchema::isSection('rector.standard'));
        Assert::false(ConfigSchema::isSection('rector.max_passes'));
        Assert::false(ConfigSchema::isSection('paths'));
        Assert::false(ConfigSchema::isSection('unknown'));
    }

    public function committedJsonSchemaIsUpToDate(): void
    {
        Assert::same(
            (string) \file_get_contents(SchemaDump::TARGET),
            JsonSchemaGenerator::toJson(),
            'resources/opmin.schema.json is stale, run `composer schema:dump`',
        );
    }

    public function jsonSchemaForbidsUnknownKeysAtEveryLevel(): void
    {
        $schema = JsonSchemaGenerator::generate();

        Assert::false($schema['additionalProperties']);
        Assert::false($schema['properties']['rector']['additionalProperties']);
        Assert::false($schema['properties']['rector']['properties']['standard']['additionalProperties']);
        Assert::same($schema['properties']['verification']['properties']['seed']['type'], 'integer');
        Assert::same(
            $schema['properties']['tests']['properties']['runner']['enum'],
            ['auto', 'phpunit', 'pest', 'testo', 'command', 'none'],
        );
    }
}
