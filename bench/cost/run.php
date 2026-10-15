<?php

/**
 * Execution cost of opmin's Rector rules and of the standard ones it lists: machine instructions per
 * call (callgrind), before and after the rewrite, on PHP 8.1–8.5, without JIT and with the tracing JIT.
 * Opcode counts often disagree with the cost (docs/rule-cost.md), so rules are judged by it.
 *
 *   bin/bench --cost                          # every rule, PHP 8.1–8.5 in Docker (bench/cost/Dockerfile)
 *   bin/bench --cost --versions=8.1,8.5       # some versions
 *   bin/bench --cost --rules=Ifm,Sw           # some rules (keys of bench/cost/cases.php)
 *   bin/bench --cost --local                  # php and valgrind on PATH instead of Docker (Linux)
 *   bin/bench --cost --report                 # only the report of the last measurement
 *
 * Steps: the code "before" of every case (cases.php) is rewritten by the rule itself (Rector) into the
 * code "after"; the cases where the rule did not fire are skipped. Both versions must return the same
 * on every input. Then every case, input and variant gets a small file of its own
 * (runtime/bench-cost/jobs/<rule>.<function>.<input>-<a|b>.php) that loads only its own code and calls the case from a
 * loop — a warm-up, 1000 and 3000 calls — with `usleep(0)` between the segments; callgrind dumps its
 * counters there (`--dump-before=usleep`). Each file runs in a process of its own: measured together,
 * the same case differed by up to 2×. Cost per call = (segment of 3000 − segment of 1000) / 2000, so
 * the start of PHP, the JIT compilation and the marker cancel out.
 *
 * Needs Docker (or Linux with valgrind for --local). Reference results: docs/rule-cost.md.
 */

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

const ROOT = __DIR__ . '/../..';
const WORK = ROOT . '/runtime/bench-cost';
const IMAGE = 'opmin-bench-cost';
const N1 = 1000;
const N2 = 3000;
const WARM = 600;

/**
 * @param list<string> $argv
 */
function benchCost(array $argv): int
{
    $options = \getopt('', ['cost', 'versions:', 'rules:', 'local', 'report', 'php:', 'only:']);
    $versions = \explode(',', \is_string($options['versions'] ?? null) ? $options['versions'] : '8.1,8.2,8.3,8.4,8.5');
    $local = isset($options['local']);
    /** @var array<string, array{rule: string, options: array<string, mixed>|null, code: string, inputs: array<string, array<string, string>>}> $cases */
    $cases = require __DIR__ . '/cases.php';
    if (\is_string($options['rules'] ?? null)) {
        $cases = \array_intersect_key($cases, \array_flip(\explode(',', $options['rules'])));
        $cases !== [] or throw new \InvalidArgumentException('--rules: no such keys in bench/cost/cases.php.');
    }

    if (!isset($options['report'])) {
        \is_dir(WORK) or \mkdir(WORK, 0777, true);
        # Dumps of earlier jobs do not match the new ones.
        sh('rm -rf ' . \escapeshellarg(WORK . '/out'));
        $manifest = generate($cases);
        check($cases, $manifest);
        if ($local) {
            measure(\trim((string) \shell_exec('php -r "echo PHP_MAJOR_VERSION, \'.\', PHP_MINOR_VERSION;"')), true);
        } else {
            foreach ($versions as $version) {
                measure($version, false);
            }
        }
    }

    report();

    return 0;
}

function sh(string $command): string
{
    \exec($command . ' 2>&1', $out, $code);
    $code === 0 or throw new \RuntimeException("Failed ({$code}): {$command}\n" . \implode("\n", \array_slice($out, -20)));

    return \implode("\n", $out);
}

/**
 * @return array<string, string> Function or `method:<name>` => printed body.
 */
function bodies(string $file): array
{
    $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse((string) \file_get_contents($file)) ?? [];
    $printer = new Standard();
    $out = [];
    foreach ((new NodeFinder())->find($ast, static fn(Node $n): bool => $n instanceof Node\Stmt\Function_ || $n instanceof Node\Stmt\ClassMethod) as $fn) {
        \assert($fn instanceof Node\Stmt\Function_ || $fn instanceof Node\Stmt\ClassMethod);
        $name = $fn instanceof Node\Stmt\ClassMethod ? 'method:' . $fn->name->toString() : $fn->name->toString();
        $out[$name] = $printer->prettyPrint($fn->stmts ?? []);
    }

    return $out;
}

/**
 * Writes the code before and after every rule and the job files.
 *
 * @param array<string, array{rule: string, options: array<string, mixed>|null, code: string, inputs: array<string, array<string, string>>}> $cases
 * @return array<string, array<string, bool>> Rule key => function => the rule fired.
 */
function generate(array $cases): array
{
    sh('rm -rf ' . \escapeshellarg(WORK . '/work'));
    $manifest = [];
    foreach ($cases as $key => $spec) {
        foreach (['a', 'b'] as $variant) {
            \mkdir(WORK . "/work/{$key}/{$variant}", 0777, true);
            \file_put_contents(
                WORK . "/work/{$key}/{$variant}/{$key}.php",
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace Bench\\{$key}\\" . \strtoupper($variant) . ";\n\n{$spec['code']}\n",
            );
        }

        $config = WORK . "/work/{$key}/rector.php";
        $rule = \var_export($spec['rule'], true);
        $register = $spec['options'] === null || $spec['options'] === []
            ? "    \$c->rule({$rule});\n"
            : "    \$c->ruleWithConfiguration({$rule}, " . \var_export($spec['options'], true) . ");\n";
        \file_put_contents($config, "<?php\nreturn static function (Rector\\Config\\RectorConfig \$c): void {\n"
            . '    $c->paths([' . \var_export(WORK . "/work/{$key}/b", true) . "]);\n"
            . '    $c->cacheDirectory(' . \var_export(WORK . "/work/{$key}/cache", true) . ");\n"
            # The rewrite itself is measured on every version, also where a rule is limited by php.target.
            . "    \$c->phpVersion(80100);\n"
            . "    \$c->disableParallel();\n"
            . $register . "};\n");
        sh('cd ' . \escapeshellarg(ROOT) . ' && vendor/bin/rector process --config=' . \escapeshellarg($config) . ' --no-progress-bar --no-diffs');

        $before = bodies(WORK . "/work/{$key}/a/{$key}.php");
        $after = bodies(WORK . "/work/{$key}/b/{$key}.php");
        $changedMethods = \array_keys(\array_filter(
            $before,
            static fn(string $body, string $name): bool => \str_starts_with($name, 'method:') && ($after[$name] ?? null) !== $body,
            \ARRAY_FILTER_USE_BOTH,
        ));
        foreach (\array_keys($spec['inputs']) as $fn) {
            $fired = ($after[$fn] ?? null) !== ($before[$fn] ?? null);
            # A function that wraps a method: the rule fired if the method it calls changed.
            if (!$fired && $changedMethods !== [] && \preg_match('/->(\w+)\(/', $before[$fn] ?? '', $m) === 1) {
                $fired = \in_array('method:' . $m[1], $changedMethods, true);
            }

            $manifest[$key][$fn] = $fired;
        }

        require_once WORK . "/work/{$key}/a/{$key}.php";
        require_once WORK . "/work/{$key}/b/{$key}.php";
        $fired = \array_keys(\array_filter($manifest[$key]));
        $skipped = \array_keys(\array_filter($manifest[$key], static fn(bool $f): bool => !$f));
        \printf("%-22s fired: %d%s\n", $key, \count($fired), $skipped === [] ? '' : '; did not fire: ' . \implode(', ', $skipped));
    }

    writeBench($cases, $manifest);

    return $manifest;
}

/**
 * @param array<string, array{rule: string, options: array<string, mixed>|null, code: string, inputs: array<string, array<string, string>>}> $cases
 * @param array<string, array<string, bool>> $manifest
 */
function writeBench(array $cases, array $manifest): void
{
    # One small file per job (case × input) and variant: it loads only its own variant of its own rule,
    # so a measurement does not depend on the other cases (see measure.sh).
    sh('rm -rf ' . \escapeshellarg(WORK . '/jobs'));
    \mkdir(WORK . '/jobs', 0777, true);
    $jobs = [];
    foreach ($cases as $key => $spec) {
        foreach ($spec['inputs'] as $fn => $inputs) {
            if (!$manifest[$key][$fn]) {
                continue;
            }

            foreach ($inputs as $input => $expression) {
                $jobs[] = [$key, $fn, $input];
                # The name of a job file depends only on the case: the length of the path shifts the
                # alignment of strings, and with it the instructions of libc's string functions.
                $name = "{$key}.{$fn}.{$input}";
                foreach (['a', 'b'] as $variant) {
                    $ns = "Bench\\{$key}\\" . \strtoupper($variant);
                    $args = \str_replace('%NS%', '\\' . $ns, $expression);
                    /** @var array<mixed> $values */
                    $values = eval("return {$args};");
                    $params = \implode(', ', \array_map(static fn(int $i): string => "\$p{$i}", \array_keys($values)));
                    $unpack = $values === [] ? '' : "[{$params}] = \$in; ";
                    $php = "<?php\n// Generated by bin/bench --cost: {$key} / {$fn} / {$input}, variant {$variant}.\n"
                        . "require __DIR__ . '/../work/{$key}/{$variant}/{$key}.php';\n"
                        # A loop of its own: one call site, one target (monomorphic for the JIT).
                        . "function run(array \$in, int \$n): void { {$unpack}for (\$i = 0; \$i < \$n; ++\$i) { \\{$ns}\\{$fn}({$params}); } }\n"
                        . "\$in = {$args};\n";
                    foreach ([WARM, N1, N2] as $n) {
                        $php .= "usleep(0); run(\$in, {$n});\n";
                    }

                    \file_put_contents(WORK . "/jobs/{$name}-{$variant}.php", $php . "usleep(0);\n");
                }
            }
        }
    }

    \file_put_contents(WORK . '/jobs.json', \json_encode(['n1' => N1, 'n2' => N2, 'jobs' => $jobs], \JSON_THROW_ON_ERROR));
    \printf("jobs: %d (case × input), each before and after\n", \count($jobs));
}

/**
 * The code before and after must return the same on every input.
 *
 * @param array<string, array{rule: string, options: array<string, mixed>|null, code: string, inputs: array<string, array<string, string>>}> $cases
 * @param array<string, array<string, bool>> $manifest
 */
function check(array $cases, array $manifest): void
{
    $different = [];
    foreach ($cases as $key => $spec) {
        foreach ($spec['inputs'] as $fn => $inputs) {
            if (!$manifest[$key][$fn]) {
                continue;
            }

            foreach ($inputs as $input => $expression) {
                $results = [];
                foreach (['A', 'B'] as $variant) {
                    /** @var array<mixed> $args */
                    $args = eval('return ' . \str_replace('%NS%', "\\Bench\\{$key}\\{$variant}", $expression) . ';');
                    $function = "\\Bench\\{$key}\\{$variant}\\{$fn}";
                    \assert(\is_callable($function));
                    $out = $function(...$args);
                    $results[$variant] = \str_replace("\\{$variant}\\", '\X\\', \is_object($out) ? $out::class : \var_export($out, true));
                }

                $results['A'] === $results['B'] or $different[] = "{$key}/{$fn}/{$input}: {$results['A']} ≠ {$results['B']}";
            }
        }
    }

    $different === [] or throw new \RuntimeException("The rewrite changed results:\n" . \implode("\n", $different));
    echo "check: before and after return the same on every input\n";
}

function measure(string $version, bool $local): void
{
    $out = WORK . "/out/{$version}";
    if ($local) {
        echo sh('sh ' . \escapeshellarg(__DIR__ . '/measure.sh') . ' ' . \escapeshellarg($out) . ' ' . \escapeshellarg(WORK . '/jobs')), "\n";

        return;
    }

    $image = IMAGE . ':' . $version;
    if (\trim(sh('docker images -q ' . \escapeshellarg($image))) === '') {
        echo "building {$image}…\n";
        sh('docker build -q --build-arg PHP=' . \escapeshellarg($version) . ' -t ' . \escapeshellarg($image) . ' ' . \escapeshellarg(__DIR__));
    }

    $work = (string) \realpath(WORK);
    echo "PHP {$version}:\n", sh(\sprintf(
        # seccomp=unconfined lets measure.sh switch off address randomization (setarch -R).
        'docker run --rm --security-opt seccomp=unconfined -v %s:/w -v %s:/m:ro %s sh /m/measure.sh /w/out/%s /w/jobs',
        \escapeshellarg($work),
        \escapeshellarg((string) \realpath(__DIR__)),
        \escapeshellarg($image),
        \escapeshellarg($version),
    )), "\n";
}

/**
 * Cost per call from the dumps of every measured version and mode; writes results.tsv, prints the summary.
 */
function report(): void
{
    $meta = \json_decode((string) \file_get_contents(WORK . '/jobs.json'), true, flags: \JSON_THROW_ON_ERROR);
    \assert(\is_array($meta) && \is_array($meta['jobs']));
    # Summary line of one callgrind dump: the instructions of one segment between two usleep().
    $ir = static function (string $file): int {
        foreach (\file($file) ?: [] as $line) {
            if (\str_starts_with($line, 'summary:')) {
                return (int) \substr($line, 8);
            }
        }

        throw new \RuntimeException("No summary in {$file}.");
    };
    # Dumps of one process: <prefix>.<k> holds the segment that ends at the k-th usleep().
    $dumps = static function (string $dir) use ($ir): array {
        $pid = null;
        foreach (\glob("{$dir}/cg.*") ?: [] as $file) {
            \preg_match('/cg\.(\d+)$/', $file, $m) === 1 and $pid = $m[1];
        }

        $pid !== null or throw new \RuntimeException("No callgrind output in {$dir}.");
        $out = [];
        foreach (\glob("{$dir}/cg.{$pid}.*") ?: [] as $file) {
            $out[(int) \substr($file, \strrpos($file, '.') + 1)] = $ir($file);
        }

        return $out;
    };
    $perCall = static fn(int $n1, int $n2): float => \round(($n2 - $n1) / ($meta['n2'] - $meta['n1']), 1);
    $rows = [];
    foreach (\glob(WORK . '/out/*', \GLOB_ONLYDIR) ?: [] as $versionDir) {
        $version = \basename($versionDir);
        # A process per job and variant: startup, warm-up, N1, N2.
        foreach (['off', 'tracing'] as $mode) {
            if (!\is_dir("{$versionDir}/{$mode}")) {
                continue;
            }

            foreach ($meta['jobs'] as [$key, $fn, $input]) {
                $a = $dumps("{$versionDir}/{$mode}/{$key}.{$fn}.{$input}-a");
                $b = $dumps("{$versionDir}/{$mode}/{$key}.{$fn}.{$input}-b");
                $rows[] = [$version, $mode, $key, $fn, $input, $perCall($a[3], $a[4]), $perCall($b[3], $b[4])];
            }
        }
    }

    $rows !== [] or throw new \RuntimeException('Nothing measured yet: run bin/bench --cost without --report.');
    $fh = \fopen(WORK . '/results.tsv', 'w');
    \assert($fh !== false);
    \fputcsv($fh, ['version', 'mode', 'rule', 'function', 'input', 'before', 'after'], "\t", '"', '');
    foreach ($rows as $row) {
        \fputcsv($fh, $row, "\t", '"', '');
    }

    \fclose($fh);

    # A rewrite is worse on an input when it costs more than 1 instruction and 0.5 % more; the summary
    # is the sum over the inputs. Agreed tolerance for accepting a rule: no input worse than 5 % or 15
    # instructions, cheaper in the sum, in both JIT modes.
    $by = [];
    $columns = [];
    foreach ($rows as [$version, $mode, $key, , , $before, $after]) {
        $column = "{$version} {$mode}";
        $columns[$column] = true;
        $cell = $by[$key][$column] ?? ['before' => 0.0, 'after' => 0.0, 'worse' => 0, 'over' => 0];
        $cell['before'] += $before;
        $cell['after'] += $after;
        $after - $before > 1 && ($after - $before) / $before > 0.005 and ++$cell['worse'];
        $after - $before > 15 && ($after - $before) / $before > 0.05 and ++$cell['over'];
        $by[$key][$column] = $cell;
    }

    $columns = \array_keys($columns);
    \usort($columns, static fn(string $a, string $b): int => [\explode(' ', $a)[1], $a] <=> [\explode(' ', $b)[1], $b]);
    echo "\nSum over the inputs, after vs before; ✗N — inputs worse beyond the tolerance (5 % and 15 instructions)\n";
    \printf('%-22s', 'rule');
    foreach ($columns as $column) {
        \printf('%15s', $column);
    }

    echo "\n";
    foreach ($by as $key => $cells) {
        \printf('%-22s', $key);
        foreach ($columns as $column) {
            $cell = $cells[$column] ?? null;
            \printf('%15s', $cell === null ? '—' : \sprintf('%+.0f%%%s', ($cell['after'] - $cell['before']) * 100 / $cell['before'], $cell['over'] > 0 ? " ✗{$cell['over']}" : ''));
        }

        echo "\n";
    }

    echo "\nPer input: " . (string) \realpath(WORK . '/results.tsv') . "\n";
}
