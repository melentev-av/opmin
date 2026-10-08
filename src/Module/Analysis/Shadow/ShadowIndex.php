<?php

declare(strict_types=1);

namespace Opmin\Module\Analysis\Shadow;

use Internal\Path;
use Opmin\Info;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Project\FileFinder;
use Opmin\Module\Project\Project;

/**
 * Project-wide index of everything that can shadow a global function or constant inside a namespace
 * (brief, 2.3): `strlen()` in `namespace App` first looks for `App\strlen`, and `\strlen()` turns this
 * fallback off — wrong when `App\strlen` exists or a test mocks it (php-mock, ClockMock).
 *
 * Scans every `*.php` of the project, `tests/` and `vendor/` included; per-file results are cached
 * by path, size and mtime in `cache.dir/shadows.json`. `phpunit.xml(.dist)` with the Symfony
 * listener's `time-sensitive`/`dns-sensitive` namespaces covers those functions everywhere.
 *
 * @internal
 */
final class ShadowIndex
{
    /** Bump when the collected data changes. */
    private const FORMAT = 1;

    /** Directories never scanned. */
    private const SKIP = ['node_modules', '.git', 'runs', 'playground'];

    /** @var array<lowercase-string, true> */
    private array $functions = [];

    /** @var array<non-empty-string, true> */
    private array $constants = [];

    /** @var array<string, array{lowercase-string|null, lowercase-string|null}> */
    private array $mocks = [];

    public static function build(Project $project, ?Path $cacheDir): self
    {
        $skip = self::SKIP;
        $cacheDir === null or $cacheDir->isWithin($project->root) and $skip[] = $project->relative($cacheDir);
        $cacheFile = $cacheDir?->join('shadows.json');
        $cache = self::readCache($cacheFile);
        $fresh = [];
        $collector = new ShadowCollector();
        $index = new self();
        foreach ((new FileFinder())->find($project, [$project->root], $skip) as $file) {
            $relative = $project->relative($file);
            $stat = @\stat((string) $file);
            if ($stat === false) {
                continue;
            }

            $stamp = $stat['size'] . ':' . $stat['mtime'];
            $entry = $cache[$relative] ?? null;
            if ($entry === null || $entry[0] !== $stamp) {
                $content = @\file_get_contents((string) $file);
                $shadows = $content === false
                    ? new FileShadows()
                    : $collector->collect($content, \str_starts_with($relative, 'vendor/') || \str_contains($relative, '/vendor/'));
                $entry = [$stamp, $shadows->isEmpty() ? null : $shadows->toArray()];
            }

            $fresh[$relative] = $entry;
            $entry[1] === null or $index->add(FileShadows::fromArray($entry[1]));
        }

        foreach (['phpunit.xml', 'phpunit.xml.dist', 'phpunit.dist.xml'] as $config) {
            $xml = @\file_get_contents((string) $project->root->join($config));
            if ($xml === false) {
                continue;
            }

            \str_contains($xml, 'time-sensitive') and $index->mockAll(ShadowCollector::CLOCK_FUNCTIONS);
            \str_contains($xml, 'dns-sensitive') and $index->mockAll(ShadowCollector::DNS_FUNCTIONS);
        }

        $cacheFile === null or $fresh === $cache or self::writeCache($cacheFile, $fresh);

        return $index;
    }

    /**
     * @param array<array-key, mixed> $data {@see self::toArray()}
     */
    public static function fromArray(array $data): self
    {
        /** @var array{functions: list<lowercase-string>, constants: list<non-empty-string>, mocks: list<array{lowercase-string|null, lowercase-string|null}>} $data */
        $index = new self();
        $index->add(new FileShadows($data['functions'], $data['constants'], $data['mocks']));

        return $index;
    }

    public function add(FileShadows $shadows): void
    {
        foreach ($shadows->functions as $function) {
            $this->functions[$function] = true;
        }

        foreach ($shadows->constants as $constant) {
            $this->constants[$constant] = true;
        }

        foreach ($shadows->mocks as [$namespace, $function]) {
            $this->mocks[($namespace ?? '*') . '|' . ($function ?? '*')] = [$namespace, $function];
        }
    }

    /**
     * Whether `$function()` inside `$namespace` may become `\$function()`: no function of that name
     * is declared or mocked in the namespace.
     */
    public function canQualifyFunction(string $namespace, string $function): bool
    {
        $namespace = \strtolower(\trim($namespace, '\\'));
        $function = \strtolower($function);
        if ($namespace === '') {
            # In the global namespace there is no fallback to turn off.
            return true;
        }

        if (isset($this->functions[$namespace . '\\' . $function])) {
            return false;
        }

        foreach ($this->mocks as [$mockNamespace, $mockFunction]) {
            if (($mockNamespace === null || $mockNamespace === $namespace) && ($mockFunction === null || $mockFunction === $function)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether `$constant` inside `$namespace` may become `\$constant`.
     */
    public function canQualifyConstant(string $namespace, string $constant): bool
    {
        $namespace = \strtolower(\trim($namespace, '\\'));

        return $namespace === '' || !isset($this->constants[$namespace . '\\' . $constant]);
    }

    /**
     * Whether a global (not namespaced) user function of that name is declared in the project.
     */
    public function hasGlobalFunction(string $function): bool
    {
        return isset($this->functions[\strtolower(\ltrim($function, '\\'))]) && !\str_contains(\ltrim($function, '\\'), '\\');
    }

    /**
     * Whether a global user constant of that name is declared (`define('X')`, `const X` outside a namespace).
     */
    public function hasGlobalConstant(string $constant): bool
    {
        $constant = \ltrim($constant, '\\');

        return !\str_contains($constant, '\\') && isset($this->constants[$constant]);
    }

    /**
     * @return array{functions: list<lowercase-string>, constants: list<non-empty-string>, mocks: list<array{lowercase-string|null, lowercase-string|null}>}
     */
    public function toArray(): array
    {
        $functions = \array_keys($this->functions);
        $constants = \array_keys($this->constants);
        \sort($functions, \SORT_STRING);
        \sort($constants, \SORT_STRING);
        $mocks = $this->mocks;
        \ksort($mocks, \SORT_STRING);

        return ['functions' => $functions, 'constants' => $constants, 'mocks' => \array_values($mocks)];
    }

    /**
     * @return array<non-empty-string, array{string, array<array-key, mixed>|null}>
     */
    private static function readCache(?Path $file): array
    {
        $raw = $file === null ? false : @\file_get_contents((string) $file);
        /** @var mixed $data */
        $data = $raw === false ? null : \json_decode($raw, true);
        if (!\is_array($data) || ($data['format'] ?? null) !== self::FORMAT . ':' . Info::version() || !\is_array($data['files'] ?? null)) {
            return [];
        }

        /** @var array<non-empty-string, array{string, array<array-key, mixed>|null}> */
        return $data['files'];
    }

    /**
     * @param array<non-empty-string, array{string, array<array-key, mixed>|null}> $files
     */
    private static function writeCache(Path $file, array $files): void
    {
        # A name or a path that is not UTF-8 cannot be JSON: then the project is not cached.
        $json = \json_encode(['format' => self::FORMAT . ':' . Info::version(), 'files' => $files], \JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        FS::mkdir((string) $file->parent());
        $tmp = (string) $file . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
        \file_put_contents($tmp, $json);
        \rename($tmp, (string) $file);
    }

    /**
     * @param list<lowercase-string> $functions
     */
    private function mockAll(array $functions): void
    {
        foreach ($functions as $function) {
            $this->mocks['*|' . $function] = [null, $function];
        }
    }
}
