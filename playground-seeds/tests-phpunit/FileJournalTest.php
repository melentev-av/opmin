<?php

declare(strict_types=1);

namespace PlaygroundSeeds\Tests;

use PHPUnit\Framework\TestCase;
use PlaygroundSeeds\FileJournal;

/**
 * The project's test of a side-effecting class: the differential tester leaves it to tests like this.
 */
final class FileJournalTest extends TestCase
{
    public function testAppendsLines(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'journal');
        $journal = new FileJournal($path);

        self::assertSame(2, $journal->append('a'));
        self::assertSame(3, $journal->append('bc'));
        self::assertSame("a\nbc\n", \file_get_contents($path));
        \unlink($path);
    }
}
