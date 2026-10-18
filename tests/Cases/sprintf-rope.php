<?php

declare(strict_types=1);

/**
 * From PHP 8.4 the compiler turns `\sprintf()` with a literal format of `%s`/`%d` into a rope itself,
 * so a concatenation instead costs more there. Zend/zend_compile.c (zend_compile_func_sprintf).
 * Rule: SprintfToConcatRector, only for a target below 8.4.
 */

return [
    'rule' => 'Opmin\Rector\Rule\SprintfToConcatRector',
    'before' => "function f(string \$s, int \$i) { return \\sprintf('%s-%d', \$s, \$i); }",
    'after' => "function f(string \$s, int \$i) { return \$s . '-' . \$i; }",
    'expect' => [
        '8.1-8.3' => ['gone' => ['DO_ICALL', 'INIT_FCALL', 'SEND_VAL', 'SEND_VAR'], 'new' => ['FAST_CONCAT'], 'ops' => '-'],
        '8.4-8.5' => ['gone' => ['CAST', 'ROPE_ADD', 'ROPE_END', 'ROPE_INIT'], 'new' => ['FAST_CONCAT'], 'ops' => '-'],
    ],
];
