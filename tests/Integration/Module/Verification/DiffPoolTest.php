<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Module\Verification;

use Internal\Path;
use Opmin\Module\Common\Timings;
use Opmin\Module\Config\Schema\Verification;
use Opmin\Module\Verification\DiffPool;
use Opmin\Module\Verification\DiffTask;
use Opmin\Module\Verification\Verdict;
use Opmin\Module\Verification\VerdictStatus;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The differential tests of a step in processes of their own: the same verdicts, in the order of the
 * tasks, as one after another in the opmin process.
 */
#[Test]
#[Covers(DiffPool::class)]
final class DiffPoolTest
{
    private const ORIGINAL = '<?php namespace App; '
        . 'function same(int $a) { return $a * 2; } '
        . 'function differs(string $s, int $n) { if ($s === "open sesame" && $n > 100) { return 1; } return 0; } '
        . 'function slow(int $a) { return $a + 1; }';
    private const CHANGED = '<?php namespace App; '
        . 'function same(int $a) { return $a + $a; } '
        . 'function differs(string $s, int $n) { return 0; } '
        . 'function slow(int $a) { return 1 + $a; }';

    private string $dir;

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-pool-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0777, true);
    }

    #[AfterTest]
    public function removeProject(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function parallelVerdictsAreTheOnesOfOneAfterAnother(): void
    {
        $serial = $this->verify(1);
        $timings = new Timings();
        $parallel = $this->verify(3, $timings);

        Assert::same(\array_map(static fn(Verdict $v): string => $v->key, $parallel), ['App\same', 'App\differs', 'App\slow']);
        Assert::same(
            \array_map(static fn(Verdict $v): VerdictStatus => $v->status, $parallel),
            [VerdictStatus::Equivalent, VerdictStatus::Mismatch, VerdictStatus::Equivalent],
        );
        Assert::same(\array_map(self::essence(...), $parallel), \array_map(self::essence(...), $serial));
        Assert::same($parallel[1]->counterexample?->input->args, $serial[1]->counterexample?->input->args);
        $levels = $timings->toArray()['levels'];
        Assert::same($levels['diff_test']['calls'], 3);
        Assert::array($levels)->hasKeys('diff_test.pool', 'diff_test.start', 'diff_test.inputs');
    }

    /**
     * @return array{string, VerdictStatus, string, float, int}
     */
    private static function essence(Verdict $verdict): array
    {
        return [$verdict->key, $verdict->status, $verdict->reason, $verdict->coverage, $verdict->inputs];
    }

    /**
     * @param positive-int $jobs
     * @return list<Verdict>
     */
    private function verify(int $jobs, ?Timings $timings = null): array
    {
        $file = Path::create($this->dir)->join('Code.php');
        \file_put_contents((string) $file, self::ORIGINAL);
        $config = new Verification();
        $config->jobs = $jobs;
        $config->fuzzTimeMs = 300;
        $pool = new DiffPool(TestPhp::binary(), $config, Path::create($this->dir), null, $timings ?? new Timings(), static function (string $m): void {});
        $tasks = \array_map(
            static fn(string $key): DiffTask => new DiffTask($key, $file, 'Code.php', self::ORIGINAL, self::CHANGED),
            ['App\same', 'App\differs', 'App\slow'],
        );

        return $pool->verify($tasks, false);
    }
}
