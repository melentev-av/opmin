<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Module\Opcode;

use Opmin\Module\Opcode\Dump\FileDump;
use Opmin\Module\Opcode\Dump\OpcacheDumper;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Compiles real files with `php.binary` (see {@see TestPhp}).
 */
#[Test]
#[Covers(OpcacheDumper::class)]
final class OpcacheDumperTest
{
    private string $dir;

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-dumper-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir);
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function batchGivesTheSameDumpsAsOneByOne(): void
    {
        $files = $this->files([
            # Declarations that later files use: the optimizer must not see them.
            'A.php' => "<?php\nnamespace B;\nconst LIMIT = 10;\nfinal class Cfg { public const X = 5; public static function s(): int { return 1; } }\nfunction helper(int \$a): int { return \$a; }\n",
            'B.php' => "<?php\nnamespace B;\nclass User { public function f(int \$a): int { return helper(\$a) + Cfg::X + LIMIT + Cfg::s() + \\strlen('ab'); } }\n",
            'C.php' => (string) \file_get_contents(__DIR__ . '/../../../Fixtures/Count/Basic.php'),
        ]);

        $batch = $this->dump($files, batchSize: 10);
        $single = $this->dump($files, batchSize: 1);

        Assert::same($batch, $single);
        Assert::string($batch[$files[1]])->contains('B\User::f:');
        Assert::string($batch[$files[2]])->contains('DECLARE_ANON_CLASS');
    }

    public function fileRedeclaringFunctionOfEarlierFileIsRetriedAlone(): void
    {
        # opcache_compile_file() declares top-level functions: in one process the second file fails.
        $files = $this->files([
            'D1.php' => "<?php\nnamespace X;\nfunction same() { return 1; }\n",
            'D2.php' => "<?php\nnamespace X;\nfunction same() { return 2 + 2; }\n",
            'D3.php' => "<?php\nnamespace X;\nfunction other() { return 3; }\n",
        ]);
        $dumper = new OpcacheDumper(TestPhp::binary(), workers: 1, batchSize: 10);

        $results = $this->collect($dumper, $files);

        Assert::same(\array_map(static fn(FileDump $d): ?string => $d->error, $results), \array_fill_keys($files, null));
        Assert::string($results[$files[1]]->dump)->contains('X\same:');
        Assert::same($dumper->processes, 2);
    }

    public function syntaxErrorFailsOnlyItsFile(): void
    {
        $files = $this->files([
            'Good1.php' => "<?php\nfunction good1() { return 1; }\n",
            'Bad.php' => "<?php\nfunction bad( {\n",
            'Good2.php' => "<?php\nfunction good2() { return 2; }\n",
        ]);

        $results = $this->collect(new OpcacheDumper(TestPhp::binary(), workers: 2, batchSize: 10), $files);

        Assert::null($results[$files[0]]->error);
        Assert::null($results[$files[2]]->error);
        Assert::string((string) $results[$files[1]]->error)->contains('ParseError: syntax error');
        Assert::same($results[$files[1]]->dump, '');
    }

    public function fatalCompileErrorFailsOnlyItsFile(): void
    {
        $files = $this->files([
            'Dup.php' => "<?php\nfunction dup() {}\nfunction dup() {}\n",
            'Good.php' => "<?php\nfunction good() { return 1; }\n",
        ]);

        $results = $this->collect(new OpcacheDumper(TestPhp::binary(), workers: 1, batchSize: 10), $files);

        Assert::string((string) $results[$files[0]]->error)->contains('Cannot redeclare');
        Assert::null($results[$files[1]]->error);
    }

    /**
     * @param array<non-empty-string, string> $files
     * @return list<non-empty-string>
     */
    private function files(array $files): array
    {
        $paths = [];
        foreach ($files as $name => $code) {
            \file_put_contents($paths[] = "{$this->dir}/{$name}", $code);
        }

        return $paths;
    }

    /**
     * @param list<non-empty-string> $files
     * @param positive-int $batchSize
     * @return array<string, string> File => dump.
     */
    private function dump(array $files, int $batchSize): array
    {
        $result = [];
        foreach ($this->collect(new OpcacheDumper(TestPhp::binary(), workers: 3, batchSize: $batchSize), $files) as $file => $dump) {
            Assert::null($dump->error);
            # PHP 8.5 prints runtime keys of anonymous classes with a per-process counter
            # (`c.php:87$4`): the only allowed difference, it is an operand string, not an opcode.
            $result[$file] = (string) \preg_replace('/(@anonymous\\\\x00[^"]*)\\$[0-9a-f]+"/', '$1\\$N"', $dump->dump);
        }

        \ksort($result);

        return $result;
    }

    /**
     * @param list<non-empty-string> $files
     * @return array<string, FileDump>
     */
    private function collect(OpcacheDumper $dumper, array $files): array
    {
        $result = [];
        $dumper->dump($files, static function (FileDump $dump) use (&$result): void {
            Assert::false(isset($result[$dump->file]));
            $result[$dump->file] = $dump;
        });
        Assert::same(\count($result), \count($files));

        return $result;
    }
}
