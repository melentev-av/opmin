<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Common\Internal\Injection;

use Internal\Container\ObjectContainer;
use Opmin\Module\Common\Internal\Injection\ConfigInflector;
use Opmin\Module\Config\Exception\ConfigException;
use Opmin\Module\Config\Schema\Php;
use Opmin\Module\Config\Schema\TestRunner;
use Opmin\Module\Config\Schema\Tests;
use Opmin\Module\Config\Schema\Verification;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ConfigInflector::class)]
final class ConfigInflectorTest
{
    public function keepsDefaultsWithoutSources(): void
    {
        $config = $this->inflect(new Verification(), new ConfigInflector());

        Assert::same($config->seed, 42);
        Assert::same($config->memoryLimit, '256M');
    }

    public function fileValueReplacesDefault(): void
    {
        $config = $this->inflect(new Verification(), new ConfigInflector(values: ['verification.seed' => 1]));

        Assert::same($config->seed, 1);
    }

    public function explicitNullInFileReplacesDefault(): void
    {
        $config = $this->inflect(new Php(), new ConfigInflector(values: ['php.target' => null, 'php.binary' => 'php8.1']));

        Assert::null($config->target);
        Assert::same($config->binary, 'php8.1');
    }

    public function environmentBeatsFile(): void
    {
        $inflector = new ConfigInflector(
            env: ['OPMIN_VERIFICATION_SEED' => '2'],
            values: ['verification.seed' => 1],
        );

        Assert::same($this->inflect(new Verification(), $inflector)->seed, 2);
    }

    public function setOverrideBeatsEnvironment(): void
    {
        $inflector = new ConfigInflector(
            env: ['OPMIN_VERIFICATION_SEED' => '2'],
            values: ['verification.seed' => 1],
            overrides: ['verification.seed' => 3],
        );

        Assert::same($this->inflect(new Verification(), $inflector)->seed, 3);
    }

    public function environmentValueIsCastToEnum(): void
    {
        $config = $this->inflect(new Tests(), new ConfigInflector(env: ['OPMIN_TESTS_RUNNER' => 'pest']));

        Assert::same($config->runner, TestRunner::Pest);
    }

    public function invalidEnvironmentValueFails(): never
    {
        Expect::exception(ConfigException::class)
            ->withMessage('Config key `verification.seed` expects int, got string "x" (env OPMIN_VERIFICATION_SEED).');

        $this->inflect(new Verification(), new ConfigInflector(env: ['OPMIN_VERIFICATION_SEED' => 'x']));
    }

    public function ignoresObjectsWithoutInflectableConfig(): void
    {
        $object = new \ArrayObject();

        Assert::same((new ConfigInflector(values: ['verification.seed' => 1]))->inflect($object, new ObjectContainer()), $object);
    }

    /**
     * @template T of object
     * @param T $config
     * @return T
     */
    private function inflect(object $config, ConfigInflector $inflector): object
    {
        $inflector->inflect($config, new ObjectContainer());

        return $config;
    }
}
