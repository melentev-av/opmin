<?php

declare(strict_types=1);

/**
 * `\count()` and `\strlen()` compile to opcodes of their own (`COUNT`, `STRLEN`), an unqualified call
 * in a namespace stays a call by name. Zend/zend_compile.c (zend_try_compile_special_func).
 * Rule: FullyQualifyGlobalCallsRector.
 */

return [
    'rule' => 'Opmin\Rector\Rule\FullyQualifyGlobalCallsRector',
    'before' => 'function f(array $a, string $s) { return count($a) + strlen($s); }',
    'after' => 'function f(array $a, string $s) { return \count($a) + \strlen($s); }',
    'expect' => [
        '8.1-8.5' => ['gone' => ['DO_FCALL_BY_NAME', 'INIT_NS_FCALL_BY_NAME', 'SEND_VAR_EX'], 'new' => ['COUNT', 'STRLEN'], 'ops' => '-'],
    ],
];
