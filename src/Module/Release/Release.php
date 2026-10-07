<?php

declare(strict_types=1);

namespace Opmin\Module\Release;

/**
 * A published release: version and download URLs of its assets.
 *
 * @internal
 */
final readonly class Release
{
    public const CHECKSUMS = 'sha256sum.txt';
    public const SIGNATURE = 'sha256sum.txt.sig';
    public const PHAR = 'opmin.phar';

    /**
     * @param non-empty-string $version Without the `v` prefix.
     * @param array<non-empty-string, non-empty-string> $assets URL by file name.
     */
    public function __construct(
        public string $version,
        public array $assets,
    ) {}

    /**
     * @return non-empty-string
     * @throws ReleaseException
     */
    public function url(string $asset): string
    {
        return $this->assets[$asset] ?? throw new ReleaseException("Release {$this->version} has no asset {$asset}.");
    }
}
