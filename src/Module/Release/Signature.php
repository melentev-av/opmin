<?php

declare(strict_types=1);

namespace Opmin\Module\Release;

use Opmin\Info;

/**
 * The signature of `sha256sum.txt`: ECDSA P-256 over SHA-256 (`openssl dgst -sha256 -sign`), made by the
 * release workflow with the key in the secret `OPMIN_SIGNING_KEY`.
 *
 * The public keys ship inside opmin, so a release replaced together with its checksums is still rejected:
 * whoever replaces it has no private key. Two keys are trusted: the primary one (in CI) and a reserve one
 * kept offline. When the primary key is lost or leaks, releases are signed with the reserve key, and the
 * installed versions keep accepting them; then a new reserve key is added in the next release.
 *
 * @internal
 */
final readonly class Signature
{
    public const KEYS_DIR = Info::ROOT_DIR . '/resources/release-keys';

    /**
     * @param non-empty-list<string> $publicKeys PEM; a signature of any of them is accepted.
     */
    public function __construct(
        private array $publicKeys,
    ) {}

    /**
     * @throws ReleaseException When the key files are missing.
     */
    public static function bundled(): self
    {
        $keys = [];
        foreach (['primary', 'reserve'] as $name) {
            $key = @\file_get_contents(self::KEYS_DIR . "/{$name}.pub.pem");
            $key === false or $keys[] = $key;
        }

        $keys === [] and throw new ReleaseException('The release public keys are missing in this build of opmin: ' . self::KEYS_DIR);

        return new self($keys);
    }

    /**
     * @param string $signature DER signature (`sha256sum.txt.sig`).
     * @throws ReleaseException When the signature does not match or cannot be checked.
     */
    public function verify(string $content, string $signature): void
    {
        \function_exists('openssl_verify') or throw new ReleaseException(
            'The PHP running opmin has no openssl extension, so the release signature cannot be checked. Nothing was installed.',
        );

        foreach ($this->publicKeys as $pem) {
            $key = \openssl_pkey_get_public($pem);
            $key === false and throw new ReleaseException('A release public key of opmin is not a valid PEM key.');
            if (\openssl_verify($content, $signature, $key, \OPENSSL_ALGO_SHA256) === 1) {
                return;
            }
        }

        throw new ReleaseException(
            'The signature of sha256sum.txt does not match the opmin release keys. Nothing was installed. Either the '
            . 'release was not published by the opmin release workflow, or the release keys were replaced after this '
            . 'version: reinstall opmin once with install.sh (README, «Installation») to get the new keys.',
        );
    }
}
