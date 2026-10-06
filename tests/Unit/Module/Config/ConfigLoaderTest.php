<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Config;

use Opmin\Module\Config\ConfigLoader;
use Opmin\Module\Config\ConfigSchema;
use Opmin\Module\Config\ConfigWriter;
use Opmin\Module\Config\Exception\ConfigException;
use Opmin\Module\Config\Schema\TestRunner;
use Opmin\Module\Config\Schema\WarningsPolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ConfigLoader::class)]
final class ConfigLoaderTest
{
    public static function invalidDocuments(): iterable
    {
        yield 'unknown top-level key' => ["verificaton:\n  seed: 1\n", 'Unknown config key `verificaton`'];
        yield 'unknown nested key' => ["verification:\n  sed: 1\n", 'Unknown config key `verification.sed`'];
        yield 'unknown deep key' => ["rector:\n  standard:\n    enable: true\n", 'Unknown config key `rector.standard.enable`'];
        yield 'scalar instead of section' => ["verification: 5\n", 'Unknown config key `verification`'];
        yield 'string for int' => ["verification:\n  seed: abc\n", 'Config key `verification.seed` expects int, got string "abc"'];
        yield 'float for int' => ["verification:\n  seed: 1.5\n", 'Config key `verification.seed` expects int'];
        yield 'string for bool' => ["verification:\n  allow_unverified: 'yes'\n", 'expects bool'];
        yield 'null for non-nullable' => ["php:\n  binary: null\n", 'Config key `php.binary` expects string, got null'];
        yield 'empty string' => ["php:\n  binary: ''\n", 'Config key `php.binary` expects string'];
        yield 'unknown enum case' => ["tests:\n  runner: jest\n", 'expects one of auto | phpunit | pest | testo | command'];
        yield 'map instead of list' => ["paths:\n  a: src\n", 'Config key `paths` expects a list of strings'];
        yield 'list item not string' => ["paths: [src, 1]\n", 'Config key `paths` expects a list of strings'];
        yield 'list instead of map' => ["rector:\n  custom_rules: [A]\n", 'Config key `rector.custom_rules` expects a map'];
        yield 'list document' => ["- a\n- b\n", 'must contain a map'];
        yield 'broken yaml' => ["paths: [src\n", 'Invalid YAML'];
    }

    public static function invalidOverrides(): iterable
    {
        yield 'no equals sign' => ['verification.seed', 'expected key=value'];
        yield 'empty key' => ['=1', 'expected key=value'];
        yield 'unknown key' => ['foo.bar=1', 'Unknown config key `foo.bar` (--set)'];
        yield 'section instead of key' => ['verification=1', 'Unknown config key `verification`'];
        yield 'not an int' => ['verification.seed=7x', 'expects int, got string "7x" (--set)'];
        yield 'not a bool' => ['guard_perf.enabled=maybe', 'expects bool'];
    }

    public function emptyDocumentGivesNoValues(): void
    {
        Assert::same(ConfigLoader::loadString(''), []);
        Assert::same(ConfigLoader::loadString("# only a comment\n"), []);
    }

    public function valuesAreTypedByKeyPath(): void
    {
        $values = ConfigLoader::loadString(<<<YAML
            paths: [src, lib]
            php:
              target: '8.4'
            tests:
              runner: pest
            verification:
              warnings: allow_removal
              float_tolerance: 0
            check:
              max_ops_new_function: null
            YAML);

        Assert::same($values, [
            'paths' => ['src', 'lib'],
            'php.target' => '8.4',
            'tests.runner' => TestRunner::Pest,
            'verification.warnings' => WarningsPolicy::AllowRemoval,
            'verification.float_tolerance' => 0.0,
            'check.max_ops_new_function' => null,
        ]);
    }

    #[DataProvider('invalidDocuments')]
    public function rejectsInvalidDocument(string $yaml, string $message): never
    {
        Expect::exception(ConfigException::class)->withMessageContaining($message);

        ConfigLoader::loadString($yaml);
    }

    public function errorNamesTheSource(): never
    {
        Expect::exception(ConfigException::class)->withMessageContaining('(custom.yaml)');

        ConfigLoader::loadString("foo: 1\n", 'custom.yaml');
    }

    public function overridesAreParsedByKeyType(): void
    {
        $values = ConfigLoader::parseOverrides([
            'verification.seed=7',
            'verification.allow_unverified=true',
            'exclude=vendor, tests ,build',
            'check.max_ops_new_function=null',
            'rector.custom_rules={"Opmin\\\\Rule":false}',
            'commands.format=vendor/bin/pint {files} --flag=x',
        ]);

        Assert::same($values, [
            'verification.seed' => 7,
            'verification.allow_unverified' => true,
            'exclude' => ['vendor', 'tests', 'build'],
            'check.max_ops_new_function' => null,
            'rector.custom_rules' => ['Opmin\Rule' => false],
            'commands.format' => 'vendor/bin/pint {files} --flag=x',
        ]);
    }

    #[DataProvider('invalidOverrides')]
    public function rejectsInvalidOverride(string $override, string $message): never
    {
        Expect::exception(ConfigException::class)->withMessageContaining($message);

        ConfigLoader::parseOverrides([$override]);
    }

    public function renderedConfigLoadsBackToDefaults(): void
    {
        $values = ConfigLoader::loadString(ConfigWriter::render());

        $defaults = \array_map(static fn($key): mixed => $key->default, ConfigSchema::keys());
        Assert::same($values, $defaults);
    }
}
