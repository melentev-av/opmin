<?php

declare(strict_types=1);

namespace Opmin\Module\Package;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;
use Symfony\Component\Process\Process;

/**
 * The clone of a git package in a temporary workspace with a unique name: the given ref checked out on a
 * new branch `opmin/<timestamp>`, so every accepted step of the run is a commit there.
 *
 * Clones run on the host with the user's git credentials; nothing of the package runs here.
 *
 * @internal
 */
final readonly class Checkout
{
    /** Written by composer and opmin in the clone: they must not make the work tree dirty. */
    private const EXCLUDE = ['/vendor/', '/composer.lock', '/.opmin-cache/', '/runs/', '/.opmin-host.yaml'];

    /**
     * @param Path $workspace The temporary directory; removed by {@see self::remove()}.
     * @param Path $dir The clone: `<workspace>/package`.
     * @param non-empty-string $branch
     * @param non-empty-string $name Package name for file names (`vendor-package`).
     */
    private function __construct(
        public Path $workspace,
        public Path $dir,
        public string $branch,
        public string $name,
        public ?string $ref,
    ) {}

    /**
     * @param non-empty-string $url
     * @param Path $root Where the workspace directory is made.
     * @throws PackageException
     */
    public static function create(string $url, ?string $ref, Path $root): self
    {
        $workspace = $root->join('opmin-package-' . \date('Ymd-His') . '-' . \bin2hex(\random_bytes(4)));
        FS::mkdir((string) $workspace);
        $dir = $workspace->join('package');
        $branch = 'opmin/' . \date('Ymd-His');
        try {
            self::git($workspace, 'clone', '--quiet', '--no-recurse-submodules', $url, (string) $dir);
            $ref === null or self::git($dir, 'checkout', '--quiet', '--detach', $ref);
            self::git($dir, 'checkout', '--quiet', '-b', $branch);
            \file_put_contents((string) $dir->join('.git', 'info', 'exclude'), "\n" . \implode("\n", self::EXCLUDE) . "\n", \FILE_APPEND);
        } catch (PackageException $e) {
            self::removeTree((string) $workspace);
            throw $e;
        }

        return new self($workspace, $dir, $branch, self::name($dir, $url), $ref);
    }

    /**
     * Removes a directory without following symbolic links: a package can link anywhere (composer path
     * repositories, its own test fixtures), and only the link itself belongs to the workspace.
     */
    public static function removeTree(string $path): void
    {
        if (\is_link($path) || \is_file($path)) {
            @\unlink($path);
            return;
        }

        if (!\is_dir($path)) {
            return;
        }

        @\chmod($path, 0700);
        $entries = \scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            $entry === '.' || $entry === '..' or self::removeTree($path . \DIRECTORY_SEPARATOR . $entry);
        }

        @\rmdir($path);
    }

    public function composerJson(): string
    {
        $content = @\file_get_contents((string) $this->dir->join('composer.json'));
        $content === false and throw new PackageException('The package has no composer.json: git package mode needs a composer package.');

        return $content;
    }

    public function remove(): void
    {
        self::removeTree((string) $this->workspace);
    }

    /**
     * `vendor-package` from composer.json, else the last part of the URL.
     *
     * @return non-empty-string
     */
    private static function name(Path $dir, string $url): string
    {
        /** @var mixed $composer */
        $composer = \json_decode((string) @\file_get_contents((string) $dir->join('composer.json')), true);
        /** @var mixed $name */
        $name = \is_array($composer) ? ($composer['name'] ?? null) : null;
        \is_string($name) or $name = \basename(\rtrim($url, '/'), '.git');
        $name = \trim((string) \preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '-');

        return $name === '' ? 'package' : $name;
    }

    /**
     * @throws PackageException
     */
    private static function git(Path $cwd, string ...$args): void
    {
        $process = new Process(['git', ...$args], (string) $cwd, ['GIT_TERMINAL_PROMPT' => '0']);
        $process->setTimeout(600);
        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new PackageException('git cannot be started: ' . $e->getMessage(), previous: $e);
        }

        $process->isSuccessful() or throw new PackageException(\sprintf(
            'git %s failed: %s',
            $args[0],
            \trim($process->getErrorOutput() . $process->getOutput()) ?: 'exit code ' . (string) $process->getExitCode(),
        ));
    }
}
