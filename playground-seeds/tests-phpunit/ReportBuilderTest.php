<?php

declare(strict_types=1);

namespace PlaygroundSeeds\Tests;

use PHPUnit\Framework\TestCase;
use PlaygroundSeeds\ReportBuilder;
use PlaygroundSeeds\ReportConfig;

final class ReportBuilderTest extends TestCase
{
    public function testHeader(): void
    {
        $builder = new ReportBuilder(new ReportConfig('Sales', 'en'));

        self::assertSame("Sales (en)\n==========", $builder->header());
    }
}
