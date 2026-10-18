<?php

declare(strict_types=1);

/**
 * `\str_starts_with()` instead of `\substr()` and a comparison: one call, no substring. From PHP 8.4
 * both are frameless calls (`FRAMELESS_ICALL_*`). Zend/zend_compile.c (zend_compile_frameless_icall).
 * Rule: StrStartsWithRector.
 */

return [
    'rule' => 'Opmin\Rector\Rule\StrStartsWithRector',
    'before' => "function f(string \$s) { return \\substr(\$s, 0, 3) === 'abc'; }",
    'after' => "function f(string \$s) { return \\str_starts_with(\$s, 'abc'); }",
    'expect' => [
        '8.1-8.3' => ['gone' => ['IS_IDENTICAL'], 'ops' => '-'],
        '8.4-8.5' => ['gone' => ['FRAMELESS_ICALL_3', 'IS_IDENTICAL', 'OP_DATA'], 'new' => ['FRAMELESS_ICALL_2'], 'ops' => '-'],
    ],
];
