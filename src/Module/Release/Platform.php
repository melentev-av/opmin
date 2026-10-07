<?php

declare(strict_types=1);

namespace Opmin\Module\Release;

/**
 * Target of a static binary: `<os>-<arch>` as in the names of the release archives.
 *
 * @internal
 */
final class Platform
{
    /** @var list<non-empty-string> Targets the release workflow builds. */
    public const TARGETS = ['linux-x86_64', 'linux-aarch64', 'macos-x86_64', 'macos-arm64'];

    /**
     * @return non-empty-string
     * @throws ReleaseException On an OS or architecture without a binary.
     */
    public static function current(): string
    {
        return self::of(\PHP_OS_FAMILY, \php_uname('m'));
    }

    /**
     * @param string $osFamily {@see \PHP_OS_FAMILY}.
     * @param string $machine `uname -m`.
     * @return non-empty-string
     * @throws ReleaseException
     */
    public static function of(string $osFamily, string $machine): string
    {
        $os = match ($osFamily) {
            'Linux' => 'linux',
            'Darwin' => 'macos',
            default => throw new ReleaseException("There is no opmin binary for {$osFamily}: use the PHAR or the Docker image."),
        };
        $arch = match (\strtolower($machine)) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => $os === 'macos' ? 'arm64' : 'aarch64',
            default => throw new ReleaseException("There is no opmin binary for {$os} on {$machine}: use the PHAR or the Docker image."),
        };

        return "{$os}-{$arch}";
    }

    /**
     * Name of the release archive with the binary.
     *
     * @return non-empty-string
     */
    public static function archive(string $version, string $target): string
    {
        return "opmin-{$version}-{$target}.tar.gz";
    }
}
