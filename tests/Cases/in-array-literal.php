<?php

declare(strict_types=1);

/**
 * A strict `\in_array()` with a literal array becomes `IN_ARRAY`, a hash lookup, instead of a chain of
 * comparisons. Zend/zend_compile.c (zend_compile_func_in_array). Rule: OrIdenticalToInArrayRector.
 */

return [
    'rule' => 'Opmin\Rector\Rule\OrIdenticalToInArrayRector',
    'before' => "function f(string \$s) { return \$s === 'a' || \$s === 'b' || \$s === 'c'; }",
    'after' => "function f(string \$s) { return \\in_array(\$s, ['a', 'b', 'c'], true); }",
    'expect' => [
        '8.1-8.5' => ['gone' => ['IS_IDENTICAL', 'JMPNZ_EX'], 'new' => ['IN_ARRAY'], 'ops' => '-'],
    ],
];
