<?php

declare(strict_types=1);

namespace Opmin\Module\Doctor;

/**
 * One line of `opmin doctor`: what was checked, the outcome, and how to fix it.
 *
 * @internal
 */
final readonly class Check
{
    /**
     * @param non-empty-string $name
     * @param string|null $fix What to do; null when nothing is wrong.
     */
    public function __construct(
        public string $name,
        public Status $status,
        public string $message,
        public ?string $fix = null,
    ) {}

    /**
     * @param non-empty-string $name
     */
    public static function ok(string $name, string $message): self
    {
        return new self($name, Status::Ok, $message);
    }

    /**
     * @param non-empty-string $name
     */
    public static function info(string $name, string $message): self
    {
        return new self($name, Status::Info, $message);
    }

    /**
     * @param non-empty-string $name
     */
    public static function warning(string $name, string $message, string $fix): self
    {
        return new self($name, Status::Warning, $message, $fix);
    }

    /**
     * @param non-empty-string $name
     */
    public static function error(string $name, string $message, string $fix): self
    {
        return new self($name, Status::Error, $message, $fix);
    }

    /**
     * @param non-empty-string $name
     */
    public static function skipped(string $name): self
    {
        return new self($name, Status::Skipped, 'not checked: php.binary does not work');
    }
}
