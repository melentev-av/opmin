<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

/**
 * Writes to a file: side-effecting, must be marked so and left to the project's tests.
 */
final class FileJournal
{
    public function __construct(
        private readonly string $path,
    ) {}

    public function append(string $line): int
    {
        $written = file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND);

        return $written === false ? 0 : $written;
    }
}
