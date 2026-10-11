<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Common;

use Opmin\Module\Common\Memory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(Memory::class)]
final class MemoryTest
{
    #[DataSet(['256M', 268435456], 'megabytes')]
    #[DataSet(['2g', 2147483648], 'gigabytes, lower case')]
    #[DataSet(['512K', 524288], 'kilobytes')]
    #[DataSet(['1024', 1024], 'bytes')]
    #[DataSet(['-1', null], 'no limit')]
    #[DataSet(['lots', null], 'not a size')]
    public function bytesOfAMemoryLimit(string $limit, ?int $expected): void
    {
        Assert::same(Memory::bytes($limit), $expected);
    }
}
