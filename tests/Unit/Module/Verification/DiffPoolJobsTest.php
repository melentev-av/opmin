<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification;

use Opmin\Module\Verification\DiffPool;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(DiffPool::class)]
final class DiffPoolJobsTest
{
    private const GB = 1024 ** 3;

    #[DataSet([3, '256M', 16, null, 3], 'configured wins')]
    #[DataSet([0, '256M', 4, null, 2], 'auto: half of the cores')]
    #[DataSet([0, '256M', 32, null, 4], 'auto: at most 4')]
    #[DataSet([0, '256M', 1, null, 1], 'auto: at least 1')]
    #[DataSet([0, '256M', 16, self::GB, 2], 'auto: two workers of 256M per test in 1G')]
    #[DataSet([0, '1G', 16, self::GB, 1], 'auto: not enough memory for two tests')]
    #[DataSet([0, '-1', 16, 2 * self::GB, 2], 'auto: no memory_limit counts as 512M')]
    public function jobs(int $configured, string $memoryLimit, int $cores, ?int $available, int $expected): void
    {
        Assert::same(DiffPool::jobs($configured, $memoryLimit, $cores, $available), $expected);
    }
}
