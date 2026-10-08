<?php

declare(strict_types=1);

use PlaygroundSeeds\FileJournal;

test('appends lines', function () {
    $path = \tempnam(\sys_get_temp_dir(), 'journal');
    $journal = new FileJournal($path);

    expect($journal->append('a'))->toBe(2)
        ->and($journal->append('bc'))->toBe(3)
        ->and(\file_get_contents($path))->toBe("a\nbc\n");
    \unlink($path);
});
