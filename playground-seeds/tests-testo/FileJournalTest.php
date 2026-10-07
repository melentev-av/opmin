<?php

declare(strict_types=1);

namespace PlaygroundSeeds\Tests;

use PlaygroundSeeds\FileJournal;
use Testo\Assert;
use Testo\Test;

/**
 * The project's test of a side-effecting class: the differential tester leaves it to tests like this.
 */
#[Test]
final class FileJournalTest
{
    public function appendsLines(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'journal');
        $journal = new FileJournal($path);

        Assert::same($journal->append('a'), 2);
        Assert::same($journal->append('bc'), 3);
        Assert::same(\file_get_contents($path), "a\nbc\n");
        \unlink($path);
    }
}
