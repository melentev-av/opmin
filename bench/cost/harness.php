<?php

/**
 * Calibration of `--guard-cost` (melentev-av/opmin#66, C3): the cases of bin/bench --cost measured
 * through a harness worker — the project's code loaded by `load`, called by `cost` through the
 * harness's invoker — instead of an isolated file per case, and compared with the isolated
 * measurement input by input. Run `bin/bench --cost --versions=…` first: it writes the code before
 * and after (runtime/bench-cost/work) and the reference (runtime/bench-cost/results.tsv).
 *
 *   php bench/cost/harness.php [--versions=8.1,8.5] [--only=Fq,Hoist] [--per=rule|function|input] [--report]
 *
 * `--per`: what one worker process measures — every input of a rule (default), of a function (as
 * `--guard-cost` would: one function per process), or one input (as bin/bench --cost).
 *
 * Results: runtime/bench-cost/harness/results.tsv and a summary.
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/run.php';

const HARNESS = WORK . '/harness';
const H_WARM = 600;
const H_N1 = 1000;
const H_N2 = 3000;

/**
 * A recipe of the harness protocol for a value built on this side (docs/harness-protocol.md).
 *
 * @return array<string, mixed>
 */
function recipe(mixed $value): array
{
    return match (true) {
        $value === null => ['type' => 'null'],
        \is_bool($value) => ['type' => 'bool', 'value' => $value],
        \is_int($value) => ['type' => 'int', 'value' => $value],
        \is_float($value) => ['type' => 'float', 'value' => \var_export($value, true)],
        \is_string($value) => ['type' => 'string', 'value' => $value],
        \is_array($value) => ['type' => 'array', 'items' => \array_map(
            static fn(int|string $k, mixed $v): array => [recipe($k), recipe($v)],
            \array_keys($value),
            $value,
        )],
        \is_object($value) && (new \ReflectionClass($value))->isInternal() => ['type' => 'object', 'class' => $value::class, 'via' => 'ctor', 'args' => []],
        \is_object($value) => ['type' => 'object', 'class' => $value::class, 'via' => 'props', 'props' => props($value)],
        default => throw new \InvalidArgumentException('Cannot describe ' . \get_debug_type($value)),
    };
}

/**
 * @return array<string, array<string, mixed>>
 */
function props(object $object): array
{
    $props = [];
    foreach (\get_mangled_object_vars($object) as $mangled => $value) {
        $parts = \explode("\0", (string) $mangled);
        $name = \end($parts);
        $class = \count($parts) === 3 && $parts[1] !== '*' && $parts[1] !== $object::class ? $parts[1] . '::' : '';
        $props[$class . $name] = recipe($value);
    }

    return $props;
}

/**
 * @return list<array{string, string, string}> [rule key, function, input] of the reference run.
 */
function jobs(): array
{
    $meta = \json_decode((string) \file_get_contents(WORK . '/jobs.json'), true, flags: \JSON_THROW_ON_ERROR);
    $only = \getopt('', ['only:'])['only'] ?? null;

    return \is_string($only)
        ? \array_values(\array_filter($meta['jobs'], static fn(array $job): bool => \in_array($job[0], \explode(',', $only), true)))
        : $meta['jobs'];
}

function per(): string
{
    $per = \getopt('', ['per:'])['per'] ?? 'rule';

    return \is_string($per) ? $per : 'rule';
}

function plans(): void
{
    /** @var array<string, array{rule: string, options: array<string, mixed>|null, code: string, inputs: array<string, array<string, string>>}> $cases */
    $cases = require __DIR__ . '/cases.php';
    sh('rm -rf ' . \escapeshellarg(HARNESS));
    \mkdir(HARNESS . '/plans', 0777, true);
    $plans = [];
    foreach (jobs() as [$key, $fn, $input]) {
        foreach (['a', 'b'] as $variant) {
            $file = (string) \realpath(WORK . "/work/{$key}/{$variant}/{$key}.php");
            require_once $file;
            $ns = "Bench\\{$key}\\" . \strtoupper($variant);
            /** @var array<mixed> $args */
            $args = eval('return ' . \str_replace('%NS%', '\\' . $ns, $cases[$key]['inputs'][$fn][$input]) . ';');
            $group = match (per()) {
                'function' => "{$key}~{$fn}",
                'input' => "{$key}~{$fn}~{$input}",
                default => $key,
            };
            $plans["{$group}-{$variant}"] ??= ['worker' => (string) \realpath(__DIR__ . '/../../harness/worker.php'), 'file' => $file, 'requests' => [], 'warm' => H_WARM, 'n1' => H_N1, 'n2' => H_N2];
            $plans["{$group}-{$variant}"]['requests'][] = [
                'target' => ['kind' => 'function', 'name' => "{$ns}\\{$fn}"],
                'input' => ['args' => \array_map(recipe(...), \array_values($args)), 'strict' => false],
            ];
        }
    }

    foreach ($plans as $name => $plan) {
        \file_put_contents(HARNESS . "/plans/{$name}.json", \json_encode($plan, \JSON_THROW_ON_ERROR));
    }

    \printf("plans: %d (rule × variant), %d inputs\n", \count($plans), \count(jobs()));
}

function run(string $version): void
{
    $root = (string) \realpath(__DIR__ . '/../..');
    $harness = (string) \realpath(HARNESS);
    $script = <<<SH
        set -eu
        for mode in off tracing; do for p in {$harness}/plans/*.json; do echo "\$mode \$p"; done; done \\
          | xargs -P 4 -n 2 sh -c 'php {$root}/bench/cost/harness-driver.php "\$1" {$harness}/out/{$version}/\$0/\$(basename "\$1" .json) "\$0"'
        SH;
    echo "PHP {$version}: ", sh(\sprintf(
        'docker run --rm --security-opt seccomp=unconfined -v %s:%s -w %s %s sh -c %s',
        \escapeshellarg($root),
        \escapeshellarg($root),
        \escapeshellarg($root),
        \escapeshellarg(IMAGE . ':' . $version),
        \escapeshellarg($script),
    )), "done\n";
}

/**
 * @return list<array{float, float}> Instructions per call of every request of one process, in order: the
 *         target and the call that does nothing (the harness's own call path).
 */
function perCall(string $dir): array
{
    $dumps = [];
    foreach (\glob("{$dir}/cg.*.*") ?: [] as $file) {
        foreach (\file($file) ?: [] as $line) {
            if (\str_starts_with($line, 'summary:')) {
                $dumps[(int) \substr($file, \strrpos($file, '.') + 1)] = (int) \substr($line, 8);
            }
        }
    }

    $responses = \json_decode((string) \file_get_contents("{$dir}/responses.json"), true, flags: \JSON_THROW_ON_ERROR);
    $out = [];
    foreach ($responses['cost'] as $i => $response) {
        ($response['status'] ?? null) === 'done' or throw new \RuntimeException("{$dir}: request {$i}: " . \json_encode($response));
        # Six dumps per request: the warm-up (with the request before it), n1, n2 of the target, then of the noop.
        $out[] = [
            \round(($dumps[6 * $i + 3] - $dumps[6 * $i + 2]) / (H_N2 - H_N1), 1),
            \round(($dumps[6 * $i + 6] - $dumps[6 * $i + 5]) / (H_N2 - H_N1), 1),
        ];
    }

    return $out;
}

function compare(): void
{
    $reference = [];
    $fh = \fopen(WORK . '/results.tsv', 'r');
    \fgetcsv($fh, null, "\t", '"', '');
    while (($row = \fgetcsv($fh, null, "\t", '"', '')) !== false) {
        $reference[\implode('/', \array_slice($row, 0, 5))] = [(float) $row[5], (float) $row[6]];
    }

    $rows = [];
    $byKey = [];
    foreach (jobs() as [$key, $fn, $input]) {
        $group = match (per()) {
            'function' => "{$key}~{$fn}",
            'input' => "{$key}~{$fn}~{$input}",
            default => $key,
        };
        $byKey[$group][] = [$key, $fn, $input];
    }

    foreach (\glob(HARNESS . '/out/*', \GLOB_ONLYDIR) ?: [] as $versionDir) {
        $version = \basename($versionDir);
        foreach (['off', 'tracing'] as $mode) {
            foreach ($byKey as $group => $list) {
                $a = perCall("{$versionDir}/{$mode}/{$group}-a");
                $b = perCall("{$versionDir}/{$mode}/{$group}-b");
                foreach ($list as $i => [$key, $fn, $input]) {
                    $ref = $reference["{$version}/{$mode}/{$key}/{$fn}/{$input}"] ?? null;
                    # The cost of the function itself: the target minus the harness's call path.
                    $ref === null or $rows[] = [$version, $mode, $key, $fn, $input, ...$ref, $a[$i][0] - $a[$i][1], $b[$i][0] - $b[$i][1], $a[$i][1]];
                }
            }
        }
    }

    $fh = \fopen(HARNESS . '/results.tsv', 'w');
    \fputcsv($fh, ['version', 'mode', 'rule', 'function', 'input', 'ref_before', 'ref_after', 'harness_before', 'harness_after', 'harness_noop'], "\t", '"', '');
    $agree = $disagree = 0;
    $deltas = $overhead = $noops = [];
    $disagreements = [];
    foreach ($rows as $row) {
        \fputcsv($fh, $row, "\t", '"', '');
        [$version, $mode, $key, $fn, $input, $ra, $rb, $ha, $hb, $noop] = $row;
        $refPct = ($rb - $ra) * 100 / $ra;
        $harnessPct = ($hb - $ha) * 100 / \max(1.0, $ha);
        $refDiff = $rb - $ra;
        $harnessDiff = $hb - $ha;
        $deltas[] = \abs($refDiff - $harnessDiff);
        $overhead[] = $ha - $ra;
        $noops[] = $noop;
        # The sign that decides, ignoring differences within the tolerance (5 % and 15 instructions).
        $sign = static fn(float $diff, float $pct): int => \abs($diff) <= 15 || \abs($pct) <= 5 ? 0 : ($diff < 0 ? -1 : 1);
        if ($sign($refDiff, $refPct) === $sign($harnessDiff, $harnessPct)) {
            ++$agree;
        } else {
            ++$disagree;
            $disagreements[] = \sprintf('%s %-7s %-14s %-16s %-8s ref %+6.1f (%+5.1f%%)  harness %+6.1f (%+5.1f%%)', $version, $mode, $key, $fn, $input, $refDiff, $refPct, $harnessDiff, $harnessPct);
        }
    }

    \fclose($fh);
    \sort($deltas);
    \sort($overhead);
    \sort($noops);
    $median = static fn(array $a): float => $a === [] ? 0.0 : $a[\intdiv(\count($a), 2)];
    \printf("inputs × versions × modes: %d; same verdict (tolerance 5 %% and 15 instructions): %d, different: %d\n", \count($rows), $agree, $disagree);
    \printf("|after − before| harness vs reference, instructions per call: median %.1f, max %.1f\n", $median($deltas), $deltas === [] ? 0 : \end($deltas));
    \printf("harness call path (noop) per call: median %.1f, min %.1f, max %.1f\n", $median($noops), $noops[0] ?? 0, $noops === [] ? 0 : \end($noops));
    \printf("left after subtracting it (harness before − reference before): median %.1f, min %.1f, max %.1f\n", $median($overhead), $overhead[0] ?? 0, $overhead === [] ? 0 : \end($overhead));
    $disagreements === [] or print("\nDifferent verdicts:\n" . \implode("\n", $disagreements) . "\n");
    echo "\nPer input: " . (string) \realpath(HARNESS . '/results.tsv') . "\n";
}

$options = \getopt('', ['versions:', 'report']);
if (!isset($options['report'])) {
    plans();
    foreach (\explode(',', \is_string($options['versions'] ?? null) ? $options['versions'] : '8.1,8.5') as $version) {
        run($version);
    }
}

compare();
