<?php

declare(strict_types=1);

namespace Opmin\Harness;

/**
 * Warnings, notices and deprecations of a call (brief 2.1): recorded with level, text and line
 * relative to the start of the function under test, or thrown as `ErrorException` like the error
 * handlers of Laravel and Symfony do. Errors silenced with `@` are recorded as suppressed and never
 * thrown.
 *
 * @internal
 */
final class ErrorCollector
{
    /** @var list<array<string, mixed>> */
    private array $errors = [];

    private int $reporting = \E_ALL;

    public function __construct(
        private bool $throw,
        private ?string $file,
        private int $startLine,
    ) {}

    public function start(): void
    {
        $this->errors = [];
        $this->reporting = \error_reporting(\E_ALL);
        \set_error_handler(function (int $level, string $message, string $file = '', int $line = 0): bool {
            $suppressed = (\error_reporting() & $level) === 0;
            $this->errors[] = [
                'level' => Value::errorLevel($level),
                'message' => $message,
                'line' => $file === $this->file ? $line - $this->startLine : null,
                'suppressed' => $suppressed,
            ];
            if ($this->throw && !$suppressed) {
                throw new \ErrorException($message, 0, $level, $file, $line);
            }

            return true;
        });
    }

    public function stop(): void
    {
        \restore_error_handler();
        \error_reporting($this->reporting);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
