<?php

declare(strict_types=1);

/**
 * `match` on string literals jumps by a table (`MATCH`) instead of one comparison per label.
 * Zend/zend_compile.c (zend_compile_match). Rule: IfChainToMatchRector.
 */

return [
    'rule' => 'Opmin\Rector\Rule\IfChainToMatchRector',
    'before' => "function f(string \$s): int { if (\$s === 'a') { return 1; } if (\$s === 'b') { return 2; } if (\$s === 'c') { return 3; } return 0; }",
    'after' => "function f(string \$s): int { return match (\$s) { 'a' => 1, 'b' => 2, 'c' => 3, default => 0 }; }",
    'expect' => [
        '8.1-8.5' => ['gone' => ['IS_IDENTICAL', 'JMPZ'], 'new' => ['JMP', 'MATCH', 'QM_ASSIGN'], 'ops' => '-'],
    ],
];
