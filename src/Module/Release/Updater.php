<?php

declare(strict_types=1);

namespace Opmin\Module\Release;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;
use Symfony\Component\Process\Process;

/**
 * Replaces the running binary or PHAR with a release.
 *
 * Order matters: the signature of `sha256sum.txt` is checked first, then the checksum of the download, then
 * the new file is started once (`--version`) — only then it is renamed over the old one, in the same
 * directory, so the replacement is atomic and a failed update leaves the old opmin in place.
 *
 * @internal
 */
final readonly class Updater
{
    /**
     * @param non-empty-string $target {@see Platform::current()}.
     */
    public function __construct(
        private ReleaseSource $source,
        private Signature $signature,
        private string $target,
    ) {}

    /**
     * @throws ReleaseException
     */
    public function update(Installation $installation, Release $release): void
    {
        $file = $installation->file ?? throw new ReleaseException('This opmin runs from sources: nothing to replace.');
        $asset = $installation->asset($release->version, $this->target);
        $dir = $file->parent();
        \is_writable((string) $dir) && (!$file->exists() || \is_writable((string) $file)) or throw new ReleaseException(
            "Cannot write {$file}: run self-update as the owner of the file (or reinstall with install.sh --dir=...).",
        );

        $content = $this->fetchVerified($release, $asset);
        $installation->kind === Installation::BINARY and $content = self::unpack($content, $asset);

        $new = $dir->join('.' . $file->name() . '.new-' . \bin2hex(\random_bytes(4)));
        try {
            \file_put_contents((string) $new, $content) === false and throw new ReleaseException("Cannot write {$new}.");
            $mode = $file->exists() ? \fileperms((string) $file) : false;
            \chmod((string) $new, $mode === false ? 0755 : $mode & 0777);
            $this->smokeTest($installation, $new, $release->version);
            \rename((string) $new, (string) $file) or throw new ReleaseException("Cannot replace {$file}.");
        } finally {
            $new->exists() and FS::removeFile($new);
        }
    }

    /**
     * Downloads an asset and proves it belongs to the release.
     *
     * @param non-empty-string $asset
     * @throws ReleaseException
     */
    public function fetchVerified(Release $release, string $asset): string
    {
        $checksums = $this->source->download($release->url(Release::CHECKSUMS));
        $this->signature->verify($checksums, $this->source->download($release->url(Release::SIGNATURE)));
        $content = $this->source->download($release->url($asset));
        Checksums::parse($checksums)->verify($asset, $content);

        return $content;
    }

    /**
     * The `opmin` file from a release archive.
     */
    private static function unpack(string $archive, string $name): string
    {
        $tmp = FS::tmpDir(sub: 'opmin-self-update-' . \bin2hex(\random_bytes(4)));
        try {
            $tarGz = $tmp->join($name);
            \file_put_contents((string) $tarGz, $archive);
            try {
                (new \PharData((string) $tarGz))->extractTo((string) $tmp->join('x'), 'opmin', true);
            } catch (\Throwable $e) {
                throw new ReleaseException("{$name} has no `opmin` file: {$e->getMessage()}", previous: $e);
            }

            $content = @\file_get_contents((string) $tmp->join('x', 'opmin'));
            $content === false || $content === '' and throw new ReleaseException("{$name} has no `opmin` file.");

            return $content;
        } finally {
            FS::remove($tmp);
        }
    }

    /**
     * A file that does not start or reports another version is never installed.
     */
    private function smokeTest(Installation $installation, Path $file, string $version): void
    {
        $command = $installation->kind === Installation::PHAR ? [\PHP_BINARY, (string) $file, '--version'] : [(string) $file, '--version'];
        $process = new Process($command);
        $process->run();
        $process->isSuccessful() && \str_contains($process->getOutput(), $version) or throw new ReleaseException(\sprintf(
            'The downloaded opmin %s does not start on this machine (exit code %s): %s',
            $version,
            (string) $process->getExitCode(),
            \trim($process->getErrorOutput() . $process->getOutput()) ?: 'no output',
        ));
    }
}
