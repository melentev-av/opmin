<?php

declare(strict_types=1);

namespace Opmin\Module\Project;

use Internal\Path;

/**
 * Collects the PHP files to analyze.
 *
 * Directories are searched recursively for `*.php`. An exclusion is a path relative to the project
 * root (`src/Legacy`); one without a slash also matches a directory or a file of that name at any
 * depth, in any case and with wildcards (`vendor`, `tests` — and `Tests/` of Symfony packages,
 * `*Test.php` next to the code). Exclusions apply only below a given path: `opmin count tests/Fixtures` counts
 * the fixtures even with `exclude: [tests]`, and a file given explicitly is always taken.
 *
 * @internal
 */
final class FileFinder
{
    /**
     * @param list<Path> $paths Absolute files and directories.
     * @param list<non-empty-string> $exclude
     * @return list<Path> Absolute, unique, sorted.
     * @throws \InvalidArgumentException When a path does not exist.
     */
    public function find(Project $project, array $paths, array $exclude): array
    {
        $files = [];
        foreach ($paths as $path) {
            if ($path->isFile()) {
                $files[(string) $path] = $path;
                continue;
            }

            $path->isDir() or throw new \InvalidArgumentException("Path `{$path}` does not exist.");
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator((string) $path, \FilesystemIterator::SKIP_DOTS),
                    fn(mixed $file): bool => $file instanceof \SplFileInfo
                        && !$this->excluded($project, $path, Path::create($file->getPathname()), $exclude)
                        && ($file->isDir() || $file->getExtension() === 'php'),
                ),
            );
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                $file->isFile() and $files[$file->getPathname()] = Path::create($file->getPathname());
            }
        }

        \ksort($files, \SORT_STRING);

        return \array_values($files);
    }

    /**
     * @param list<non-empty-string> $exclude
     */
    private function excluded(Project $project, Path $base, Path $path, array $exclude): bool
    {
        $relative = $project->relative($path);
        $baseRelative = $project->relative($base);
        $below = $baseRelative === '.' ? $relative : \substr($relative, \strlen($baseRelative) + 1);
        $segments = \explode('/', $below);
        foreach ($exclude as $pattern) {
            $pattern = \trim(\str_replace('\\', '/', $pattern), '/');
            $coversBase = $baseRelative === $pattern || \str_starts_with($baseRelative, $pattern . '/');
            if ((!$coversBase && ($relative === $pattern || \str_starts_with($relative, $pattern . '/')))
                || (!\str_contains($pattern, '/') && self::anySegment($pattern, $segments))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $segments
     */
    private static function anySegment(string $pattern, array $segments): bool
    {
        foreach ($segments as $segment) {
            if (\fnmatch($pattern, $segment, \FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }
}
