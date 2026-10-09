<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Release;

use Opmin\Info;
use Testo\Assert;
use Testo\Test;

/**
 * The Composer dist archive is built by `git archive`, and `.gitattributes` drops everything it does not
 * whitelist. An installed copy reads these paths from `Info::ROOT_DIR` at runtime.
 */
#[Test]
final class ComposerArchiveTest
{
    private const RUNTIME_PATHS = ['composer.json', 'bin/opmin', 'src', 'harness', 'resources'];

    public function keepsEveryFileAnInstalledCopyReads(): void
    {
        \exec(\sprintf(
            'git -C %1$s ls-files -- %2$s | git -C %1$s check-attr --stdin export-ignore',
            \escapeshellarg(Info::ROOT_DIR),
            \implode(' ', \array_map(\escapeshellarg(...), self::RUNTIME_PATHS)),
        ), $lines, $code);

        Assert::same($code, 0);
        Assert::true(\in_array('harness/worker.php: export-ignore: unset', $lines, true));
        Assert::same(\array_values(\preg_grep('/: export-ignore: set$/', $lines)), []);
    }
}
