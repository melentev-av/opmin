<?php

/**
 * Wrong "optimizations" of the trap seeds: [file, search, replace] applied to the copy in a scenario.
 * The weak tests of the `traps` scenario pass on each of them; `opmin verify` must reject every one.
 */
return [
    '"unused" $upper removed next to compact()' => [
        'Traps.php',
        "        \$upper = strtoupper(\$user);\n\n        return compact('user', 'id', 'upper');",
        "        return compact('user', 'id', 'upper');",
    ],
    'static state lost' => ['Traps.php', 'static $calls = 0;', '$calls = 0;'],
    'func_num_args() taken for the arity' => ['Traps.php', 'return func_num_args() * 10 + count(func_get_args());', 'return 20 + count(func_get_args());'],
    'array_key_exists() becomes isset() on null values' => ['Traps.php', 'return array_key_exists($key, $map);', 'return isset($map[$key]);'],
    '\time() bypasses the clock mock' => ['Traps.php', 'return time() > $deadline;', 'return \time() > $deadline;'],
    'magic branch dropped' => ['Traps.php', "if (\$mode === 'legacy-v1-compat') {\n            return 'legacy';\n        }\n\n        ", ''],
    '== becomes ===' => ['Traps.php', 'return $x == 0;', 'return $x === 0;'],
    'isset() becomes !== null on a magic object' => ['MagicBag.php', 'return isset($this->{$name});', 'return $this->{$name} !== null;'],
];
