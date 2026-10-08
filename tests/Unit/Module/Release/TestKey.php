<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Release;

/**
 * A test ECDSA P-256 key (`tests/Fixtures/Release`): what the release workflow signs `sha256sum.txt` with.
 * The keys are files, not generated: `openssl_pkey_new()` needs an `openssl.cnf`, which static PHP builds lack.
 */
final readonly class TestKey
{
    private function __construct(
        private \OpenSSLAsymmetricKey $private,
        public string $public,
    ) {}

    /**
     * @param 'a'|'b' $name
     */
    public static function create(string $name = 'a'): self
    {
        $key = \openssl_pkey_get_private('file://' . \dirname(__DIR__, 3) . "/Fixtures/Release/test-key-{$name}.pem");
        $key === false and throw new \RuntimeException('Cannot read the test key: ' . (string) \openssl_error_string());

        return new self($key, (string) (\openssl_pkey_get_details($key)['key'] ?? ''));
    }

    public function sign(string $content): string
    {
        \openssl_sign($content, $signature, $this->private, \OPENSSL_ALGO_SHA256) or throw new \RuntimeException('Cannot sign.');

        return $signature;
    }
}
