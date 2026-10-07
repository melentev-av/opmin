<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Release;

use Internal\Path;
use Opmin\Module\Release\Installation;
use Opmin\Module\Release\Release;
use Opmin\Module\Release\ReleaseException;
use Opmin\Module\Release\ReleaseSource;
use Opmin\Module\Release\Signature;
use Opmin\Module\Release\Updater;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Updater::class)]
#[Covers(Installation::class)]
final class UpdaterTest
{
    private const OLD = "<?php echo \"opmin 0.1.0\\n\";\n";
    private const NEW = "<?php echo \"opmin 0.2.0\\n\";\n";

    private string $dir = '';

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-updater-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir);
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function replacesThePharWithAVerifiedRelease(): void
    {
        $key = TestKey::create();
        $phar = $this->file('opmin.phar', self::OLD, 0750);

        $this->updater($key, ['opmin.phar' => self::NEW])->update(new Installation(Installation::PHAR, $phar), $this->release());

        Assert::same(\file_get_contents((string) $phar), self::NEW);
        Assert::same(\fileperms((string) $phar) & 0777, 0750);
        Assert::same(\glob($this->dir . '/.*new*'), []);
    }

    public function replacesTheBinaryWithTheFileFromTheArchive(): void
    {
        $key = TestKey::create();
        $binary = $this->file('opmin', "#!/bin/sh\necho opmin 0.1.0\n", 0755);
        $archive = $this->archive("#!/bin/sh\necho opmin 0.2.0\n");

        $this->updater($key, ['opmin-0.2.0-linux-x86_64.tar.gz' => $archive])
            ->update(new Installation(Installation::BINARY, $binary), $this->release());

        Assert::same(\file_get_contents((string) $binary), "#!/bin/sh\necho opmin 0.2.0\n");
        Assert::same(\fileperms((string) $binary) & 0777, 0755);
    }

    public function keepsTheOldFileWhenTheChecksumsAreSignedByAnotherKey(): void
    {
        $phar = $this->file('opmin.phar', self::OLD, 0755);
        $updater = $this->updater(TestKey::create('a'), ['opmin.phar' => self::NEW], signer: TestKey::create('b'));

        $error = $this->failure(fn() => $updater->update(new Installation(Installation::PHAR, $phar), $this->release()));

        Assert::string($error)->contains('does not match the opmin release keys');
        Assert::same(\file_get_contents((string) $phar), self::OLD);
    }

    public function keepsTheOldFileWhenTheDownloadDoesNotMatchTheChecksum(): void
    {
        $key = TestKey::create();
        $phar = $this->file('opmin.phar', self::OLD, 0755);
        $updater = $this->updater($key, ['opmin.phar' => self::NEW], served: ['opmin.phar' => "<?php echo 'evil';\n"]);

        $error = $this->failure(fn() => $updater->update(new Installation(Installation::PHAR, $phar), $this->release()));

        Assert::string($error)->contains('Checksum mismatch for opmin.phar');
        Assert::same(\file_get_contents((string) $phar), self::OLD);
    }

    public function keepsTheOldFileWhenTheNewOneReportsAnotherVersion(): void
    {
        $key = TestKey::create();
        $phar = $this->file('opmin.phar', self::OLD, 0755);
        $updater = $this->updater($key, ['opmin.phar' => "<?php echo \"opmin 0.3.0\\n\";\n"]);

        $error = $this->failure(fn() => $updater->update(new Installation(Installation::PHAR, $phar), $this->release()));

        Assert::string($error)->contains('does not start on this machine');
        Assert::same(\file_get_contents((string) $phar), self::OLD);
        Assert::same(\glob($this->dir . '/.*new*'), []);
    }

    public function refusesToUpdateSources(): never
    {
        Expect::exception(ReleaseException::class)->withMessageContaining('runs from sources');

        (new Installation(Installation::SOURCES, null))->asset('0.2.0', 'linux-x86_64');
    }

    /**
     * @param array<non-empty-string, string> $assets What sha256sum.txt lists.
     * @param array<non-empty-string, string> $served What is downloaded instead, when different.
     */
    private function updater(TestKey $key, array $assets, array $served = [], ?TestKey $signer = null): Updater
    {
        $sums = '';
        foreach ($assets as $name => $content) {
            $sums .= \hash('sha256', $content) . "  {$name}\n";
        }

        $files = [...$assets, ...$served, Release::CHECKSUMS => $sums, Release::SIGNATURE => ($signer ?? $key)->sign($sums)];
        $source = new class($files) implements ReleaseSource {
            /**
             * @param array<string, string> $files
             */
            public function __construct(private readonly array $files) {}

            public function latest(): Release
            {
                throw new \LogicException('not used');
            }

            public function get(string $version): Release
            {
                throw new \LogicException('not used');
            }

            public function download(string $url): string
            {
                return $this->files[\basename($url)] ?? throw new ReleaseException("Not found: {$url}");
            }
        };

        return new Updater($source, new Signature([$key->public]), 'linux-x86_64');
    }

    private function release(): Release
    {
        $assets = [];
        foreach ([Release::CHECKSUMS, Release::SIGNATURE, Release::PHAR, 'opmin-0.2.0-linux-x86_64.tar.gz'] as $name) {
            $assets[$name] = 'https://example.test/download/' . $name;
        }

        return new Release('0.2.0', $assets);
    }

    private function file(string $name, string $content, int $mode): Path
    {
        $path = $this->dir . '/' . $name;
        \file_put_contents($path, $content);
        \chmod($path, $mode);

        return Path::create($path);
    }

    private function archive(string $binary): string
    {
        $tar = $this->dir . '/build.tar';
        $data = new \PharData($tar);
        $data->addFromString('opmin', $binary);
        $data->compress(\Phar::GZ);
        unset($data);

        return (string) \file_get_contents($tar . '.gz');
    }

    /**
     * @param \Closure(): void $update
     */
    private function failure(\Closure $update): string
    {
        try {
            $update();
        } catch (ReleaseException $e) {
            return $e->getMessage();
        }

        throw new \LogicException('The update did not fail.');
    }
}
