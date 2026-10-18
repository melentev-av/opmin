<?php

declare(strict_types=1);

/**
 * A `switch` of five and more int labels already jumps by a table (`SWITCH_LONG`), so `match` gains
 * nothing there and costs more. Zend/zend_compile.c (zend_compile_switch, should_use_jumptable).
 * Rule: SwitchToMatchRector (off by default).
 */

return [
    'rule' => 'Opmin\Rector\Rule\SwitchToMatchRector',
    'before' => "function f(int \$i): string { switch (\$i) { case 1: return 'a'; case 2: return 'b'; case 3: return 'c'; case 4: return 'd'; case 5: return 'e'; default: return 'z'; } }",
    'after' => "function f(int \$i): string { return match (\$i) { 1 => 'a', 2 => 'b', 3 => 'c', 4 => 'd', 5 => 'e', default => 'z' }; }",
    'expect' => [
        '8.1-8.5' => ['gone' => ['IS_EQUAL', 'JMPNZ', 'SWITCH_LONG'], 'new' => ['JMP', 'MATCH', 'QM_ASSIGN'], 'ops' => '-'],
    ],
];
