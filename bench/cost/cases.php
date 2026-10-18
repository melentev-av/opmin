<?php

/**
 * Cases of bin/bench --cost (bench/cost/run.php): the code "before" for every rule — several forms and
 * sizes — and its inputs. The code "after" is produced by the rule itself.
 *
 * Rule key => rule class, its options (null — a standard rule without options), the code (functions of
 * one namespace; a class rule is reached through a wrapper function) and the inputs: function =>
 * [input name => PHP expression of the argument list]; `%NS%` is the namespace of the variant.
 */

declare(strict_types=1);

$chainInt = static function (int $n, bool $typed): string {
    $body = '';
    for ($i = 1; $i <= $n; $i++) {
        $body .= "if (\$i === {$i}) { return 'v{$i}'; } ";
    }
    $ret = $typed ? ': string' : '';
    return "function ifm_int{$n}" . ($typed ? '' : '_untyped') . "(int \$i){$ret} { {$body}return 'none'; }\n";
};
$chainStr = static function (int $n): string {
    $body = '';
    for ($i = 1; $i <= $n; $i++) {
        $body .= "if (\$s === 'k{$i}') { return {$i}; } ";
    }
    return "function ifm_str{$n}(string \$s): int { {$body}return 0; }\n";
};
$switchInt = static function (int $n, bool $typed): string {
    $body = '';
    for ($i = 1; $i <= $n; $i++) {
        $body .= "case {$i}: return 'v{$i}'; ";
    }
    $ret = $typed ? ': string' : '';
    return "function sw_int{$n}" . ($typed ? '' : '_untyped') . "(int \$i){$ret} { switch (\$i) { {$body}default: return 'none'; } }\n";
};
$switchStr = static function (int $n): string {
    $body = '';
    for ($i = 1; $i <= $n; $i++) {
        $body .= "case 'k{$i}': return {$i}; ";
    }
    return "function sw_str{$n}(string \$s): int { switch (\$s) { {$body}default: return 0; } }\n";
};
$intInputs = static function (int $n): array {
    $pick = \array_unique([1, \intdiv($n + 1, 2), $n]);
    $out = [];
    foreach ($pick as $p) {
        $out["arm{$p}"] = "[{$p}]";
    }
    $out['miss'] = '[0]';
    return $out;
};
$strInputs = static function (int $n): array {
    $pick = \array_unique([1, \intdiv($n + 1, 2), $n]);
    $out = [];
    foreach ($pick as $p) {
        $out["arm{$p}"] = "['k{$p}']";
    }
    $out['miss'] = "['zz']";
    return $out;
};

$ifmCode = '';
$ifmIn = [];
$swCode = '';
$swIn = [];
$ifmCode .= $chainInt(2, false);
$ifmIn['ifm_int2_untyped'] = $intInputs(2);
$swCode .= $switchInt(2, false);
$swIn['sw_int2_untyped'] = $intInputs(2);
foreach ([3, 5, 8, 12, 20] as $n) {
    $ifmCode .= $chainInt($n, true);
    $ifmIn["ifm_int{$n}"] = $intInputs($n);
    $swCode .= $switchInt($n, true);
    $swIn["sw_int{$n}"] = $intInputs($n);
}
foreach ([3, 8] as $n) {
    $ifmCode .= $chainStr($n);
    $ifmIn["ifm_str{$n}"] = $strInputs($n);
    $swCode .= $switchStr($n);
    $swIn["sw_str{$n}"] = $strInputs($n);
}

$orid = '';
$oridIn = [];
foreach ([2, 3, 5, 10] as $n) {
    $parts = [];
    for ($i = 1; $i <= $n; $i++) {
        $parts[] = "\$s === 'k{$i}'";
    }
    $orid .= "function orid{$n}(string \$s): bool { return " . \implode(' || ', $parts) . "; }\n";
    $oridIn["orid{$n}"] = ['first' => "['k1']", 'last' => "['k{$n}']", 'miss' => "['zz']"];
}

$long = "str_repeat('x', 200)";

return [
    'Fq' => [
        'rule' => 'Opmin\Rector\Rule\FullyQualifyGlobalCallsRector',
        'options' => ['shadows' => ['functions' => [], 'constants' => [], 'mocks' => []]],
        'code' => <<<'PHP'
            function fq_strlen(string $s): int { return strlen($s); }
            function fq_count(array $a): int { return count($a) + 1; }
            function fq_in_array(string $s): bool { return in_array($s, ['a', 'b', 'c', 'd'], true); }
            function fq_str_contains(string $s): bool { return str_contains($s, 'needle'); }
            function fq_mixed(string $s): string { $t = trim($s); return strtoupper(substr($t, 0, 3)) . PHP_EOL; }
            function fq_arg_type(int $x): int { $y = abs($x); return $x + $y; }
            function fq_const(): int { return PHP_INT_SIZE + E_ALL; }
            PHP,
        'inputs' => [
            'fq_strlen' => ['short' => "['hello']", 'long' => "[{$long}]"],
            'fq_count' => ['empty' => '[[]]', 'ten' => '[range(1, 10)]'],
            'fq_in_array' => ['hit' => "['a']", 'miss' => "['z']"],
            'fq_str_contains' => ['hit' => "['a needle here']", 'miss' => "[{$long}]"],
            'fq_mixed' => ['s' => "['  abcdef  ']"],
            'fq_arg_type' => ['neg' => '[-5]', 'pos' => '[5]'],
            'fq_const' => ['none' => '[]'],
        ],
    ],
    'Prop' => [
        'rule' => 'Opmin\Rector\Rule\ExtractRepeatedPropertyFetchRector',
        'options' => ['min_reads' => 'auto'],
        'code' => <<<'PHP'
            final class Logger { public int $calls = 0; public function info(string $m): void { $this->calls++; } }
            final class Holder
            {
                private int $n = 7;
                public function __construct(private readonly Logger $logger) {}
                public function square(): int { return $this->n * $this->n + 1; }
                public function log2(): void { $this->logger->info('a'); $this->logger->info('b'); }
                public function import(array $rows): void { $this->logger->info('start'); foreach ($rows as $row) { $this->logger->info($row); } }
            }
            function prop_square(Holder $h): int { return $h->square(); }
            function prop_log2(Holder $h): void { $h->log2(); }
            function prop_loop(Holder $h, array $rows): void { $h->import($rows); }
            PHP,
        'inputs' => [
            'prop_square' => ['h' => "[new %NS%\\Holder(new %NS%\\Logger())]"],
            'prop_log2' => ['h' => "[new %NS%\\Holder(new %NS%\\Logger())]"],
            'prop_loop' => ['n0' => "[new %NS%\\Holder(new %NS%\\Logger()), []]", 'n10' => "[new %NS%\\Holder(new %NS%\\Logger()), array_fill(0, 10, 'r')]"],
        ],
    ],
    'Dim' => [
        'rule' => 'Opmin\Rector\Rule\ExtractRepeatedArrayDimFetchRector',
        'options' => ['min_reads' => 'auto'],
        'code' => <<<'PHP'
            function dim_total(array $row): int { if (isset($row['qty'], $row['price'])) { return $row['qty'] * $row['price'] + $row['qty'] * $row['qty']; } return 0; }
            function dim_first(array $list): int { if (isset($list[0])) { return $list[0] + $list[0] * 2; } return 0; }
            function dim_shape(string $host, int $port): string { $config = ['host' => $host, 'port' => $port]; return $config['host'] . ':' . $config['port'] . ' (' . $config['host'] . ')'; }
            PHP,
        'inputs' => [
            'dim_total' => ['set' => "[['qty' => 3, 'price' => 10]]", 'missing' => "[['qty' => 3]]"],
            'dim_first' => ['set' => '[[5, 6]]', 'empty' => '[[]]'],
            'dim_shape' => ['v' => "['localhost', 5432]"],
        ],
    ],
    'Hoist' => [
        'rule' => 'Opmin\Rector\Rule\HoistLoopInvariantCountRector',
        'options' => [],
        'code' => <<<'PHP'
            function hoist_sum(array $a): int { $t = 0; for ($i = 0; $i < \count($a); $i++) { $t += $a[$i]; } return $t; }
            function hoist_idx(array $a): int { $t = 0; for ($i = 0; $i < \count($a); $i++) { $t += $i; } return $t; }
            PHP,
        'inputs' => [
            'hoist_sum' => ['n0' => '[[]]', 'n5' => '[range(1, 5)]', 'n100' => '[range(1, 100)]'],
            'hoist_idx' => ['n0' => '[[]]', 'n5' => '[range(1, 5)]', 'n100' => '[range(1, 100)]'],
        ],
    ],
    'Cluse' => [
        'rule' => 'Opmin\Rector\Rule\RemoveUnusedClosureUseRector',
        'options' => [],
        'code' => <<<'PHP'
            function cluse1(int $x, int $y): int { $f = function (int $z) use ($x, $y): int { return $z + $x; }; return $f(1); }
            function cluse3(int $x, int $a, int $b, int $c): int { $f = function (int $z) use ($x, $a, $b, $c): int { return $z + $x; }; return $f(1); }
            function cluse_map(array $l, int $x, string $unused): array { return \array_map(function (int $v) use ($x, $unused): int { return $v * $x; }, $l); }
            PHP,
        'inputs' => [
            'cluse1' => ['v' => '[1, 2]'],
            'cluse3' => ['v' => '[1, 2, 3, 4]'],
            'cluse_map' => ['n3' => "[[1, 2, 3], 2, 'u']", 'n30' => "[range(1, 30), 2, 'u']"],
        ],
    ],
    'Sprintf' => [
        'rule' => 'Opmin\Rector\Rule\SprintfToConcatRector',
        'options' => [],
        'code' => <<<'PHP'
            function sprintf2(string $s, int $i): string { return \sprintf('%s-%d', $s, $i); }
            function sprintf3(string $a, string $b, int $c): string { return \sprintf('%s: %s (%d)', $a, $b, $c); }
            function sprintf1(string $s): string { return \sprintf('[%s]', $s); }
            PHP,
        'inputs' => [
            'sprintf2' => ['v' => "['hello', 42]"],
            'sprintf3' => ['v' => "['key', 'value', 7]"],
            'sprintf1' => ['v' => "['hello']"],
        ],
    ],
    'Interp' => [
        'rule' => 'Opmin\Rector\Rule\ConcatToInterpolationRector',
        'options' => [],
        'code' => <<<'PHP'
            final class Entry { public function __construct(public string $dir, public string $name) {} }
            function interp3(string $a, string $b): string { return $a . '/' . $b; }
            function interp3_vars(string $a, string $b, string $c): string { return $a . $b . $c; }
            function interp5(string $a, string $b, string $c): string { return $a . '/' . $b . '/' . $c; }
            function interp9(string $a, string $b, string $c, string $d, string $e): string { return $a . '/' . $b . '/' . $c . '/' . $d . '/' . $e; }
            function interp_int(int $id, string $name): string { return '#' . $id . ': ' . $name; }
            function interp_prop(Entry $e): string { return $e->dir . '/' . $e->name . '.php'; }
            function interp_rest(string $a, string $b, string $c): string { return $a . '/' . $b . \strtoupper($c); }
            PHP,
        'inputs' => [
            'interp3' => ['short' => "['src', 'a.php']", 'long' => "[{$long}, {$long}]"],
            'interp3_vars' => ['short' => "['a', 'b', 'c']", 'long' => "[{$long}, {$long}, {$long}]"],
            'interp5' => ['short' => "['src', 'Module', 'a.php']", 'long' => "[{$long}, {$long}, {$long}]"],
            'interp9' => ['short' => "['a', 'b', 'c', 'd', 'e']"],
            'interp_int' => ['v' => "[42, 'name']"],
            'interp_prop' => ['v' => "[new %NS%\\Entry('src/Module', 'Name')]"],
            'interp_rest' => ['v' => "['src', 'a', 'php']"],
        ],
    ],
    'MatchIf' => [
        'rule' => 'Opmin\Rector\Rule\MatchTrueToIfRector',
        'options' => [],
        'code' => <<<'PHP'
            function mif2(int $n): string { return match (true) { $n < 0 => 'neg', default => 'pos' }; }
            function mif3(int $n): string { return match (true) { $n < 0 => 'neg', $n === 0 => 'zero', default => 'pos' }; }
            function mif5(int $n): int { return match (true) { $n < 10 => 1, $n < 100 => 2, $n < 1000 => 3, $n < 10000 => 4, default => 5 }; }
            function mif_untyped(int $n) { return match (true) { $n < 0 => 'neg', $n === 0 => 'zero', default => 'pos' }; }
            function mif_kind(mixed $v): string { return match (true) { \is_int($v) => 'int', \is_string($v) => 'string', $v instanceof \Countable => 'countable', default => 'other' }; }
            function mif_assign(int $n): string { $s = match (true) { $n < 0 => 'neg', $n === 0 => 'zero', default => 'pos' }; return $s . '!'; }
            PHP,
        'inputs' => [
            'mif2' => ['first' => '[-1]', 'default' => '[1]'],
            'mif3' => ['first' => '[-1]', 'second' => '[0]', 'default' => '[1]'],
            'mif5' => ['first' => '[5]', 'third' => '[500]', 'default' => '[50000]'],
            'mif_untyped' => ['first' => '[-1]', 'default' => '[1]'],
            'mif_kind' => ['int' => '[1]', 'countable' => '[new \\ArrayObject()]', 'default' => '[1.5]'],
            'mif_assign' => ['first' => '[-1]', 'default' => '[1]'],
        ],
    ],
    'Apush' => [
        'rule' => 'Opmin\Rector\Rule\ArrayPushToAssignRector',
        'options' => [],
        'code' => <<<'PHP'
            function apush1(array $a, int $x): array { \array_push($a, $x); return $a; }
            function apush2(array $a, int $x): array { \array_push($a, $x, 0); return $a; }
            function apush_build(int $n): array { $a = []; for ($i = 0; $i < $n; $i++) { \array_push($a, $i); } return $a; }
            PHP,
        'inputs' => [
            'apush1' => ['small' => '[[1, 2, 3], 4]', 'big' => '[range(1, 100), 4]'],
            'apush2' => ['small' => '[[1, 2, 3], 4]'],
            'apush_build' => ['n1' => '[1]', 'n20' => '[20]'],
        ],
    ],
    'Starts' => [
        'rule' => 'Opmin\Rector\Rule\StrStartsWithRector',
        'options' => [],
        'code' => <<<'PHP'
            function starts_substr(string $s): bool { return \substr($s, 0, 3) === 'abc'; }
            function starts_strpos(string $s): bool { return \strpos($s, 'abc') === 0; }
            function starts_not(string $s): bool { return \substr($s, 0, 3) !== 'abc'; }
            PHP,
        'inputs' => [
            'starts_substr' => ['hit' => "['abcdef']", 'miss' => "['xbcdef']", 'long' => "[{$long}]"],
            'starts_strpos' => ['hit' => "['abcdef']", 'miss' => "['xbcdef']", 'long' => "[{$long}]"],
            'starts_not' => ['hit' => "['abcdef']", 'miss' => "['xbcdef']"],
        ],
    ],
    'Orid' => [
        'rule' => 'Opmin\Rector\Rule\OrIdenticalToInArrayRector',
        'options' => ['min_items' => 2],
        'code' => $orid,
        'inputs' => $oridIn,
    ],
    'Nulls' => [
        'rule' => 'Opmin\Rector\Rule\NullCheckTernaryToNullsafeRector',
        'options' => [],
        'code' => <<<'PHP'
            final class Box { public int $v = 1; public ?Box $next = null; public function get(): int { return $this->v; } }
            function nulls_prop(?Box $o): ?int { return $o !== null ? $o->v : null; }
            function nulls_method(?Box $o): ?int { return $o !== null ? $o->get() : null; }
            function nulls_chain(?Box $o): ?Box { return $o !== null ? $o->next : null; }
            PHP,
        'inputs' => [
            'nulls_prop' => ['null' => '[null]', 'obj' => "[new %NS%\\Box()]"],
            'nulls_method' => ['null' => '[null]', 'obj' => "[new %NS%\\Box()]"],
            'nulls_chain' => ['null' => '[null]', 'obj' => "[new %NS%\\Box()]"],
        ],
    ],
    'Ifm' => [
        'rule' => 'Opmin\Rector\Rule\IfChainToMatchRector',
        'options' => ['min_arms' => 'auto'],
        'code' => $ifmCode,
        'inputs' => $ifmIn,
    ],
    'Sw' => [
        'rule' => 'Opmin\Rector\Rule\SwitchToMatchRector',
        'options' => ['min_arms' => 'auto'],
        'code' => $swCode,
        'inputs' => $swIn,
    ],
    'StdSingleInArray' => [
        'rule' => 'Rector\CodeQuality\Rector\FuncCall\SingleInArrayToCompareRector',
        'options' => null,
        'code' => "function single_strict(string \$s): bool { return \\in_array(\$s, ['a'], true); }\nfunction single_loose(string \$s): bool { return \\in_array(\$s, ['a']); }\n",
        'inputs' => ['single_strict' => ['hit' => "['a']", 'miss' => "['b']"], 'single_loose' => ['hit' => "['a']", 'miss' => "['b']"]],
    ],
    'StdFuncGetArgsCount' => [
        'rule' => 'Rector\CodeQuality\Rector\FuncCall\SimplifyFuncGetArgsCountRector',
        'options' => null,
        'code' => "function argc() { return \\count(\\func_get_args()); }\n",
        'inputs' => ['argc' => ['a0' => '[]', 'a3' => '[1, 2, 3]']],
    ],
    'StdStrlenZero' => [
        'rule' => 'Rector\CodeQuality\Rector\Identical\StrlenZeroToIdenticalEmptyStringRector',
        'options' => null,
        'code' => "function strlen_zero(string \$s): bool { return \\strlen(\$s) === 0; }\n",
        'inputs' => ['strlen_zero' => ['empty' => "['']", 'full' => "['abc']"]],
    ],
    'StdTernary' => [
        'rule' => 'Rector\CodeQuality\Rector\Ternary\UnnecessaryTernaryExpressionRector',
        'options' => null,
        'code' => "function tern(int \$a): bool { return \$a === 1 ? true : false; }\n",
        'inputs' => ['tern' => ['t' => '[1]', 'f' => '[2]']],
    ],
    'StdUnusedAssign' => [
        'rule' => 'Rector\DeadCode\Rector\Assign\RemoveUnusedVariableAssignRector',
        'options' => null,
        'code' => "function unused_pure(string \$s): int { \$x = \\strtoupper(\$s); return 1; }\nfunction unused_expr(int \$a, int \$b): int { \$x = \$a + \$b; return \$a; }\n",
        'inputs' => ['unused_pure' => ['s' => "['hello']"], 'unused_expr' => ['v' => '[1, 2]']],
    ],
    'StdDeadCondition' => [
        'rule' => 'Rector\DeadCode\Rector\Return_\RemoveDeadConditionAboveReturnRector',
        'options' => null,
        'code' => "function dead_cond(int \$a): int { if (\$a > 0) { return 1; } return 1; }\n",
        'inputs' => ['dead_cond' => ['pos' => '[1]', 'neg' => '[-1]']],
    ],
];
