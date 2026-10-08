<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Rector;

use Internal\Path;
use Opmin\Module\Common\Cache\FileStore;
use Opmin\Module\Opcode\CountCache;
use Opmin\Module\Opcode\Dump\OpcacheDumper;
use Opmin\Module\Opcode\OpcodeCounter;
use Opmin\Module\Project\Project;
use Opmin\Rector\Rule\HoistLoopInvariantCountRector;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The positive fixtures of opmin's own rules are worth it: the expected code has fewer opcodes after
 * the optimizer than the input under `php.binary` (brief, M3: «положительные кейсы дают выигрыш»).
 * {@see HoistLoopInvariantCountRector} gains executed opcodes only: its static count must not grow.
 */
#[Test]
final class RuleGainTest
{
    private const FIXTURES = __DIR__ . '/../../../src/Rector/Rule/Fixture';

    private string $dir = '';

    /**
     * @return iterable<string, array{string, string, bool}> [input, expected, executed gain only]
     */
    public static function positiveFixtures(): iterable
    {
        foreach (\glob(self::FIXTURES . '/*/*.php.inc') ?: [] as $file) {
            $parts = \explode("\n-----\n", (string) \file_get_contents($file));
            if (\count($parts) !== 2) {
                continue;
            }

            $rule = \basename(\dirname($file));
            yield $rule . '/' . \basename($file, '.php.inc') => [$parts[0], $parts[1], $rule === 'HoistLoopInvariantCount'];
        }
    }

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = \sys_get_temp_dir() . '/opmin-gain-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir);
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    #[DataProvider('positiveFixtures')]
    public function expectedCodeHasFewerOpcodes(string $input, string $expected, bool $executedOnly): void
    {
        $before = $this->opcodes($input, 'before');
        $after = $this->opcodes($expected, 'after');

        $executedOnly
            ? Assert::true($after <= $before, "ops_opt grew: {$before} → {$after}")
            : Assert::true($after < $before, "ops_opt did not drop: {$before} → {$after}");
    }

    private function opcodes(string $code, string $name): int
    {
        $file = $this->dir . "/{$name}.php";
        \file_put_contents($file, $code);
        $php = TestPhp::binary();
        $counter = new OpcodeCounter(new OpcacheDumper($php, 1), new CountCache(new FileStore(Path::create($this->dir . '/.cache')), $php));

        $result = $counter->count(new Project(Path::create($this->dir), false, null), [Path::create($file)]);

        Assert::same($result->errors, []);
        $total = 0;
        foreach ($result->functions as $function) {
            $function->optimizable and $total += $function->opsOpt;
        }

        return $total;
    }
}
