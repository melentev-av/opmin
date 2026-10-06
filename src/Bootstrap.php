<?php

declare(strict_types=1);

namespace Opmin;

use Internal\Container\Container;
use Internal\Container\ObjectContainer;
use Opmin\Module\Common\Internal\Injection\ConfigInflector;
use Opmin\Module\Config\ConfigLoader;
use Opmin\Module\Config\Exception\ConfigException;

/**
 * Bootstraps the application by configuring the dependency container.
 *
 * ```php
 * $container = Bootstrap::init()
 *     ->withConfig('opmin.yaml', $inputOptions, $inputArguments, \getenv())
 *     ->finish();
 *
 * $php = $container->get(\Opmin\Module\Config\Schema\Php::class);
 * ```
 *
 * @internal
 */
final class Bootstrap
{
    private function __construct(
        private ObjectContainer $container,
    ) {}

    public static function init(ObjectContainer $container = new ObjectContainer()): self
    {
        return new self($container);
    }

    /**
     * Finalizes the bootstrap process and returns the configured container.
     */
    public function finish(): Container
    {
        $c = $this->container;
        unset($this->container);

        return $c;
    }

    /**
     * Loads and validates the config file and registers config hydration.
     *
     * @param non-empty-string|null $file Path to `opmin.yaml`; null — defaults only.
     * @param array<string, mixed> $inputOptions Command-line options.
     * @param array<string, mixed> $inputArguments Command-line arguments.
     * @param array<string, string> $environment Environment variables.
     * @param list<string> $overrides `--set=key=value` overrides.
     * @throws ConfigException On an unknown key or a value of the wrong type.
     */
    public function withConfig(
        ?string $file = null,
        array $inputOptions = [],
        array $inputArguments = [],
        array $environment = [],
        array $overrides = [],
    ): self {
        # Every object produced by the container passes through the inflector,
        # which hydrates the ones marked with #[InflectableConfig].
        $this->container->addInflector(new ConfigInflector(
            env: $environment,
            inputArguments: $inputArguments,
            inputOptions: $inputOptions,
            values: $file === null ? [] : ConfigLoader::loadFile($file),
            overrides: ConfigLoader::parseOverrides($overrides),
        ));

        return $this;
    }
}
