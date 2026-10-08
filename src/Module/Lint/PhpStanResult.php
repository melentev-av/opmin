<?php

declare(strict_types=1);

namespace Opmin\Module\Lint;

/**
 * Errors PHPStan reported, by file and message (lines shift when code is rewritten, so they are not
 * part of the identity of an error).
 *
 * @internal
 */
final readonly class PhpStanResult
{
    /**
     * @param list<array{file: string, message: string, identifier: string, line: int}> $errors
     * @param bool $ran Whether PHPStan produced a report.
     */
    public function __construct(
        public array $errors,
        public bool $ran = true,
        public string $output = '',
    ) {}

    /**
     * Errors of `$after` that `$before` does not have (as a multiset: one more of the same counts).
     *
     * @return list<array{file: string, message: string, identifier: string, line: int}>
     */
    public static function newErrors(self $before, self $after): array
    {
        $known = [];
        foreach ($before->errors as $error) {
            $key = self::key($error);
            $known[$key] = ($known[$key] ?? 0) + 1;
        }

        $new = [];
        foreach ($after->errors as $error) {
            $key = self::key($error);
            if (($known[$key] ?? 0) > 0) {
                --$known[$key];
                continue;
            }

            $new[] = $error;
        }

        return $new;
    }

    /**
     * @param array{file: string, message: string, identifier: string, line: int} $error
     */
    private static function key(array $error): string
    {
        return $error['file'] . "\0" . $error['identifier'] . "\0" . $error['message'];
    }
}
