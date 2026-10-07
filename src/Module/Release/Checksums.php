<?php

declare(strict_types=1);

namespace Opmin\Module\Release;

/**
 * `sha256sum.txt` of a release: the SHA-256 of every asset, in the format of `sha256sum`.
 *
 * @internal
 */
final readonly class Checksums
{
    /**
     * @param array<non-empty-string, lowercase-string> $hashes Lower-case hex SHA-256 by file name.
     */
    private function __construct(
        public array $hashes,
    ) {}

    public static function parse(string $content): self
    {
        $hashes = [];
        foreach (\preg_split('/\R/', $content) ?: [] as $line) {
            # `<hash>  <name>` (text mode) or `<hash> *<name>` (binary mode)
            if (\preg_match('/^([0-9a-fA-F]{64}) [ *](\S.*)$/', \trim($line), $m) === 1) {
                $name = \basename($m[2]);
                $name === '' or $hashes[$name] = \strtolower($m[1]);
            }
        }

        return new self($hashes);
    }

    /**
     * @throws ReleaseException When the file is not listed or its content does not match.
     */
    public function verify(string $name, string $content): void
    {
        $expected = $this->hashes[$name] ?? throw new ReleaseException("{$name} is not listed in sha256sum.txt of the release.");
        \hash_equals($expected, \hash('sha256', $content)) or throw new ReleaseException(
            "Checksum mismatch for {$name}: the download is corrupted or was replaced. Nothing was installed.",
        );
    }
}
