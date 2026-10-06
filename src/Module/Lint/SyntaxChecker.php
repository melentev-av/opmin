<?php

declare(strict_types=1);

namespace Opmin\Module\Lint;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Php\PhpBinary;
use Symfony\Component\Process\Process;

/**
 * Level 1 of verification, syntax: `php -l` under `php.binary` (the production PHP decides what
 * syntax is valid).
 *
 * @internal
 */
final readonly class SyntaxChecker
{
    public function __construct(
        private PhpBinary $php,
        private Path $workDir,
    ) {}

    /**
     * @return string|null The error, null when the code is valid.
     */
    public function check(string $code): ?string
    {
        FS::mkdir((string) $this->workDir);
        $file = $this->workDir->join('lint-' . \bin2hex(\random_bytes(4)) . '.php');
        \file_put_contents((string) $file, $code);
        try {
            $process = new Process([$this->php->path, '-d', 'display_errors=stderr', '-l', (string) $file]);
            $process->run();
            if ($process->isSuccessful()) {
                return null;
            }

            $message = \trim($process->getErrorOutput() . "\n" . $process->getOutput());

            return \trim(\str_replace([(string) $file, 'Errors parsing ', 'No syntax errors detected in'], ['the file', '', ''], $message)) ?: 'Syntax error';
        } finally {
            FS::removeFile($file);
        }
    }
}
