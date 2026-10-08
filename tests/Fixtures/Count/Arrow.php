<?php

// Fixture for opcode counting: PHP ends an arrow function on the line of the token after its body
// (the parser's lookahead), so one whose argument list closes on the next line ends there.

declare(strict_types=1);

namespace Fixture\Count;

final class Arrow
{
    public function lastArgument(array $records, int $offset): array
    {
        return \array_map(
            \array_filter($records, fn(array $record): bool => isset($record[$offset])),
            fn(array $record) => $record[$offset]
        );
    }

    public function multiline(): void
    {
        \set_error_handler(
            fn(int $errno): bool =>
            \in_array($errno, [\E_WARNING], true)
                ? throw new \ErrorException('warning')
                : false
            // A comment is not a token of the parser.
        );
    }

    public function sameLine(): \Closure
    {
        return fn(int $x): int => $x * 2;
    }

    public function after(int $x): int
    {
        return $x + 1;
    }
}
