<?php

declare(strict_types=1);

namespace Opmin\Module\Project;

use Internal\Path;
use Opmin\Module\Config\Schema;

/**
 * The project and the paths a command works on (brief, «Целевая кодовая база»): the arguments —
 * files, directories, globs (`src/**\/*Service.php`), relative to the current directory — or the
 * config `paths`, relative to the project root.
 *
 * @internal
 */
final class Targets
{
    /**
     * @param list<string> $arguments
     * @return array{Project, list<Path>}
     * @throws \InvalidArgumentException When a path does not exist or a glob matches nothing.
     */
    public static function resolve(array $arguments, Path $cwd, Schema\Project $config): array
    {
        $paths = [];
        foreach ($arguments as $argument) {
            if (\strpbrk($argument, '*?[') !== false) {
                $matches = self::glob(self::absolute($argument, $cwd));
                $matches === [] and throw new \InvalidArgumentException("The glob `{$argument}` matches no PHP file.");
                \array_push($paths, ...$matches);
                continue;
            }

            $path = self::absolute($argument, $cwd);
            $path->exists() or throw new \InvalidArgumentException("Path `{$path}` does not exist.");
            $paths[] = $path;
        }

        $project = Project::detect($paths[0] ?? $cwd, $cwd);
        if ($paths === []) {
            $configured = $config->paths;
            # The default `src` falls back to `app` (Laravel and similar layouts).
            $configured === (new Schema\Project())->paths && !$project->root->join('src')->exists()
                && $project->root->join('app')->isDir() and $configured = ['app'];
            $paths = \array_map(static fn(string $p): Path => $project->root->join($p), $configured);
            foreach ($paths as $path) {
                $path->exists() or throw new \InvalidArgumentException(
                    "Path `{$path}` from the config key `paths` does not exist; pass paths as arguments or fix `paths`.",
                );
            }
        }

        return [$project, $paths];
    }

    /**
     * PHP files matching a glob; `**` crosses directories.
     *
     * @return list<Path>
     */
    public static function glob(Path $pattern): array
    {
        $pattern = \str_replace('\\', '/', (string) $pattern);
        # The longest leading part without wildcards is the directory to walk.
        $wild = \strcspn($pattern, '*?[');
        $base = \substr($pattern, 0, (int) \strrpos(\substr($pattern, 0, $wild), '/'));
        if ($base === '' || !\is_dir($base)) {
            return [];
        }

        $regex = '~^' . \strtr(\preg_quote($pattern, '~'), [
            '\*\*/' => '(?:.*/)?',
            '\*\*' => '.*',
            '\*' => '[^/]*',
            '\?' => '[^/]',
            '\[' => '[',
            '\]' => ']',
        ]) . '$~';
        $matches = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = \str_replace('\\', '/', $file->getPathname());
            $file->isFile() && $file->getExtension() === 'php' && \preg_match($regex, $path) === 1 and $matches[$path] = Path::create($path);
        }

        \ksort($matches, \SORT_STRING);

        return \array_values($matches);
    }

    /**
     * An argument path relative to the current directory; an absolute one as it is (it may be
     * anywhere, `Path::absolute()` would insist on being under the directory).
     */
    public static function absolute(string $path, Path $cwd): Path
    {
        $result = Path::create($path);

        return $result->isAbsolute() ? $result : $result->absolute((string) $cwd);
    }

    /**
     * Whether a file is generated code (`@generated` in its header): never changed.
     */
    public static function generated(Path $file): bool
    {
        $head = (string) @\file_get_contents((string) $file, length: 2048);

        return \preg_match('/@generated\b/', $head) === 1;
    }
}
