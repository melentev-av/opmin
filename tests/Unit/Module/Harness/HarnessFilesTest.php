<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Harness;

use Opmin\Module\Harness\HarnessFiles;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Extraction of the harness from an archive read through `phar://` — the way it lies in the PHAR and in the
 * static binary. A tar archive stands in for the PHAR: writing a Phar needs `phar.readonly=0`.
 */
#[Test]
#[Covers(HarnessFiles::class)]
final class HarnessFilesTest
{
    private string $dir = '';

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-harness-files-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir);
        \putenv('OPMIN_HARNESS_DIR=' . $this->dir . '/extracted');
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \putenv('OPMIN_HARNESS_DIR');
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function extractsEveryPhpFileOfTheHarnessFromAPharUrl(): void
    {
        $archive = new \PharData($this->dir . '/opmin.tar');
        $archive->addFromString('harness/worker.php', "<?php // worker\n");
        $archive->addFromString('harness/src/Recipe.php', "<?php // recipe\n");
        $archive->addFromString('harness/README.md', 'not code');
        $archive->addFromString('src/Info.php', '<?php');
        unset($archive);

        # As Info::ROOT_DIR builds it inside the PHAR: `phar://<file>/src/..`.
        $worker = HarnessFiles::extract('phar://' . $this->dir . '/opmin.tar/src/../harness');

        Assert::same($worker->name(), 'worker.php');
        Assert::same(\file_get_contents((string) $worker), "<?php // worker\n");
        Assert::same(\file_get_contents((string) $worker->parent()->join('src', 'Recipe.php')), "<?php // recipe\n");
        Assert::false($worker->parent()->join('README.md')->exists());
        Assert::string((string) $worker)->startsWith($this->dir . '/extracted/opmin-harness-');
        Assert::same((string) HarnessFiles::extract('phar://' . $this->dir . '/opmin.tar/harness'), (string) $worker);
    }
}
