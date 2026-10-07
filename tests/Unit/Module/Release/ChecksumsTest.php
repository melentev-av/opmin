<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Release;

use Opmin\Module\Release\Checksums;
use Opmin\Module\Release\ReleaseException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Checksums::class)]
final class ChecksumsTest
{
    public function readsTheTextAndTheBinaryModeOfSha256sum(): void
    {
        $a = \hash('sha256', 'a');
        $b = \hash('sha256', 'b');

        $sums = Checksums::parse("{$a}  opmin-1.0.0-linux-x86_64.tar.gz\r\n" . \strtoupper($b) . " *dist/opmin.phar\n\ngarbage\n");

        Assert::same($sums->hashes, ['opmin-1.0.0-linux-x86_64.tar.gz' => $a, 'opmin.phar' => $b]);
    }

    #[\Testo\Assert\ExpectNoAssertions]
    public function acceptsTheListedContent(): void
    {
        Checksums::parse(\hash('sha256', 'phar') . "  opmin.phar\n")->verify('opmin.phar', 'phar');
    }

    public function rejectsAChangedContent(): never
    {
        Expect::exception(ReleaseException::class)->withMessageContaining('Checksum mismatch for opmin.phar');

        Checksums::parse(\hash('sha256', 'phar') . "  opmin.phar\n")->verify('opmin.phar', 'phar!');
    }

    public function rejectsAFileTheListDoesNotHave(): never
    {
        Expect::exception(ReleaseException::class)->withMessageContaining('opmin.phar is not listed');

        Checksums::parse('')->verify('opmin.phar', 'phar');
    }
}
