<?php

declare(strict_types=1);

namespace Opmin\Module\Project;

use Internal\Path;

/**
 * The analyzed project: its root and what `composer.json` says about it.
 *
 * The root is the nearest directory with `composer.json` or `opmin.yaml`/`opmin.yaml.dist` above the
 * analyzed paths: a config marks the root of bare files that live inside another project. Without
 * either, the root is the current directory.
 *
 * @internal
 */
final readonly class Project
{
    /**
     * @param Path $root Absolute.
     * @param bool $composer Whether the root has `composer.json`.
     * @param non-empty-string|null $phpTarget `config.platform.php` or the lowest `require.php` as
     *        `major.minor`; null without composer.json or constraint.
     */
    public function __construct(
        public Path $root,
        public bool $composer,
        public ?string $phpTarget,
    ) {}

    /**
     * @param Path $start Absolute directory or file to search upwards from.
     * @param Path $fallback Absolute root when no `composer.json` is found.
     */
    public static function detect(Path $start, Path $fallback): self
    {
        $dir = $start->isFile() ? $start->parent() : $start;
        while (true) {
            $composer = $dir->join('composer.json');
            if ($composer->isFile()) {
                return new self($dir, true, self::phpTarget((string) \file_get_contents((string) $composer)));
            }

            if ($dir->join('opmin.yaml')->isFile() || $dir->join('opmin.yaml.dist')->isFile()) {
                return new self($dir, false, null);
            }

            $parent = $dir->parent();
            if ((string) $parent === (string) $dir) {
                return new self($fallback, false, null);
            }

            $dir = $parent;
        }
    }

    /**
     * Path of a file relative to the root, with `/` separators.
     *
     * @return non-empty-string
     */
    public function relative(Path $file): string
    {
        $relative = $file->tryRelative($this->root);
        $result = $relative === null || !$file->isWithin($this->root) ? (string) $file : (string) $relative;
        $result = \str_replace('\\', '/', $result);

        return $result === '' ? '.' : $result;
    }

    /**
     * @return non-empty-string|null
     */
    private static function phpTarget(string $json): ?string
    {
        /** @var mixed $composer */
        $composer = \json_decode($json, true);
        if (!\is_array($composer)) {
            return null;
        }

        /** @var array{config?: array{platform?: array{php?: mixed}}, require?: array{php?: mixed}} $composer */
        $constraint = $composer['config']['platform']['php'] ?? $composer['require']['php'] ?? null;
        if (!\is_string($constraint) || \preg_match_all('/(\d+)\.(\d+)/', $constraint, $m, \PREG_SET_ORDER) === 0) {
            return null;
        }

        # The lowest version the constraint mentions: `>=8.1`, `^8.2 || ^8.3`, `8.3.16`.
        $versions = \array_map(static fn(array $v): string => $v[1] . '.' . $v[2], $m);
        \usort($versions, static fn(string $a, string $b): int => \version_compare($a, $b));

        return $versions[0];
    }
}
