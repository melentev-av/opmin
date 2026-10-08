<?php

// Fixture for opcode counting (PHP 8.1+): string literals with newlines. PHP 8.1–8.4 print strings
// unescaped in the dump, so these literals break opcode lines and imitate opcodes and a header.

declare(strict_types=1);

namespace Fixture\Count;

function newlines(string $s): string
{
    return $s . ")\n" . \strtoupper("\n\n");
}

function imitation(string $s): string
{
    $a = $s . "x\n0005 RETURN int(1)\n0006 RETURN int(2)\n";

    return \strtolower($a) . "\nLIVE RANGES:\nfake:\n     ; (lines=1, args=0, vars=0, tmps=0)\n";
}

function after(): int
{
    return 42;
}
