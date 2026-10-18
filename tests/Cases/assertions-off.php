<?php

declare(strict_types=1);

/**
 * With `zend.assertions=-1`, as in production and as opmin counts (OptimizerSettings::INI), `assert()`
 * compiles to nothing: a function with it has the opcodes of one without.
 */

return [
    'rule' => null,
    'before' => 'function f(int $x) { assert($x > 0); return $x; }',
    'after' => 'function f(int $x) { return $x; }',
    'expect' => [
        '8.1-8.5' => ['ops' => '0'],
    ],
];
