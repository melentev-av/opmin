<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Module\Php;

use Opmin\Module\Php\InternalSymbols;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Php\PhpBinaryException;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

/**
 * The internal functions and constants of `php.binary`, listed by a script under it.
 */
#[Test]
#[Covers(InternalSymbols::class)]
final class InternalSymbolsTest
{
    public function listsTheFunctionsAndConstantsOfPhpBinary(): void
    {
        $symbols = InternalSymbols::of(TestPhp::binary());

        Assert::true(\in_array('strlen', $symbols->functions, true));
        Assert::true(\in_array('PHP_VERSION', $symbols->constants, true));
    }

    public function aWarningAtStartupDoesNotBreakTheList(): void
    {
        $php = TestPhp::binary();
        # A startup warning goes to stdout with the default display_errors of the CLI.
        $noisy = new PhpBinary($php->path, $php->version, $php->versionId, [...$php->loadArgs, '-d', 'extension=opmin-no-such-extension'], $php->zendExtensions);

        $symbols = InternalSymbols::of($noisy);

        Assert::true(\in_array('strlen', $symbols->functions, true));
    }

    public function aFailureNamesWhatThePhpPrinted(): never
    {
        $php = TestPhp::binary();
        $broken = new PhpBinary($php->path, $php->version, $php->versionId, [...$php->loadArgs, '-d', 'disable_functions=json_encode'], $php->zendExtensions);

        Expect::exception(PhpBinaryException::class)->withMessageContaining('json_encode');

        InternalSymbols::of($broken);
    }
}
