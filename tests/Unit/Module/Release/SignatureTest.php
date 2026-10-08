<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Release;

use Opmin\Module\Release\ReleaseException;
use Opmin\Module\Release\Signature;
use Testo\Assert;
use Testo\Assert\ExpectNoAssertions;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Signature::class)]
final class SignatureTest
{
    #[ExpectNoAssertions]
    public function acceptsASignatureOfTheReleaseKey(): void
    {
        $key = TestKey::create();

        (new Signature([$key->public]))->verify("sums\n", $key->sign("sums\n"));
    }

    public function rejectsAChangedContent(): never
    {
        $key = TestKey::create();
        Expect::exception(ReleaseException::class)->withMessageContaining('does not match the opmin release keys');

        (new Signature([$key->public]))->verify("sums, replaced\n", $key->sign("sums\n"));
    }

    public function rejectsASignatureOfAnotherKey(): never
    {
        Expect::exception(ReleaseException::class)->withMessageContaining('does not match the opmin release keys');

        (new Signature([TestKey::create('a')->public]))->verify("sums\n", TestKey::create('b')->sign("sums\n"));
    }

    public function rejectsGarbageInsteadOfASignature(): never
    {
        Expect::exception(ReleaseException::class)->withMessageContaining('does not match the opmin release keys');

        (new Signature([TestKey::create()->public]))->verify("sums\n", 'not a signature');
    }

    #[ExpectNoAssertions]
    public function acceptsASignatureOfTheReserveKey(): void
    {
        (new Signature([TestKey::create('a')->public, TestKey::create('b')->public]))->verify("sums\n", TestKey::create('b')->sign("sums\n"));
    }

    public function bundlesTwoDifferentP256Keys(): void
    {
        $keys = [];
        foreach (['primary', 'reserve'] as $name) {
            $details = \openssl_pkey_get_details(\openssl_pkey_get_public((string) \file_get_contents(Signature::KEYS_DIR . "/{$name}.pub.pem")));
            Assert::same($details['ec']['curve_name'] ?? null, 'prime256v1');
            $keys[] = $details['key'];
        }

        Assert::true($keys[0] !== $keys[1]);
        Assert::instanceOf(Signature::bundled(), Signature::class);
    }
}
