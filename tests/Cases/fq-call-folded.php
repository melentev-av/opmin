<?php

declare(strict_types=1);

/**
 * A qualified call of a pure built-in function with literal arguments is evaluated at compile time;
 * an unqualified one in a namespace may resolve to a function of the namespace and stays a call.
 * Zend/Optimizer/sccp.c (ct_eval_func_call). Rule: FullyQualifyGlobalCallsRector.
 */

return [
    'rule' => 'Opmin\Rector\Rule\FullyQualifyGlobalCallsRector',
    'before' => "function f() { return strlen('abc'); }",
    'after' => "function f() { return \\strlen('abc'); }",
    'expect' => [
        '8.1-8.5' => ['gone' => ['DO_FCALL_BY_NAME', 'INIT_NS_FCALL_BY_NAME', 'SEND_VAL_EX'], 'ops' => '-'],
    ],
];
