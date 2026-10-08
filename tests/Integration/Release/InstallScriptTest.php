<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Release;

use Opmin\Info;
use Opmin\Module\Release\Platform;
use Opmin\Module\Release\Signature;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * `install.sh` on a release served from a local directory (`OPMIN_DOWNLOAD_BASE=file://...`). A release
 * signed with the real keys cannot be made here (the private keys are not in the repository), so the
 * tests prove the refusals and that the script trusts exactly the keys opmin ships.
 */
#[Test]
final class InstallScriptTest
{
    private const SCRIPT = Info::ROOT_DIR . '/install.sh';

    private string $dir = '';

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-install-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/release/v9.9.9', 0777, true);
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function trustsTheKeysOpminShips(): void
    {
        $script = (string) \file_get_contents(self::SCRIPT);

        foreach (['primary', 'reserve'] as $name) {
            $key = \trim((string) \file_get_contents(Signature::KEYS_DIR . "/{$name}.pub.pem"));
            Assert::string($script)->contains("KEY_" . \strtoupper($name) . "='{$key}'");
        }
    }

    public function refusesAReleaseSignedByAnotherKey(): void
    {
        $this->release(signature: 'forged');

        [$code, $err] = $this->install();

        Assert::same($code, 1);
        Assert::string($err)->contains('does not match the opmin release keys: nothing was installed');
        Assert::false(\file_exists($this->dir . '/bin/opmin'));
    }

    public function refusesAMissingRelease(): void
    {
        [$code, $err] = $this->install('--version=1.0.0');

        Assert::same($code, 1);
        Assert::string($err)->contains('cannot download opmin-1.0.0-');
    }

    public function rejectsAnUnknownArgument(): void
    {
        [$code, $err] = $this->install('--force');

        Assert::same($code, 1);
        Assert::string($err)->contains('unknown argument --force');
    }

    private function release(string $signature): void
    {
        $archive = Platform::archive('9.9.9', Platform::current());
        $dir = $this->dir . '/release/v9.9.9';
        \file_put_contents($dir . '/opmin', "#!/bin/sh\necho opmin 9.9.9\n");
        \exec(\sprintf('tar -czf %s -C %s opmin', \escapeshellarg("{$dir}/{$archive}"), \escapeshellarg($dir)));
        \file_put_contents($dir . '/sha256sum.txt', \hash_file('sha256', "{$dir}/{$archive}") . "  {$archive}\n");
        \file_put_contents($dir . '/sha256sum.txt.sig', $signature);
    }

    /**
     * @return array{int, string}
     */
    private function install(string ...$args): array
    {
        $process = \proc_open(
            ['sh', self::SCRIPT, '--dir=' . $this->dir . '/bin', ...($args === [] ? ['--version=9.9.9'] : $args)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            ['PATH' => (string) \getenv('PATH'), 'HOME' => $this->dir, 'OPMIN_DOWNLOAD_BASE' => 'file://' . $this->dir . '/release'],
        );
        \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);

        return [\proc_close($process), $err];
    }
}
