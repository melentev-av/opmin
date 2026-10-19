<?php

/**
 * Runs inside the image of bench/cost (PHP 8.1+ with valgrind, no Composer): one harness worker under
 * callgrind, as `--guard-cost` would run it, loads one variant of one rule's code and runs the `cost`
 * command on every input of the plan; callgrind dumps its counters at every usleep() of the worker.
 *
 *   php harness-driver.php <plan.json> <out-dir> <off|tracing>
 *
 * plan.json: {"worker": <harness/worker.php>, "file": <code>, "requests": [{target, input}…], "warm", "n1", "n2"}.
 * Writes <out-dir>/responses.json and the callgrind dumps <out-dir>/cg.<pid>.<k>.
 */

declare(strict_types=1);

[, $planFile, $out, $mode] = $argv;
$plan = \json_decode((string) \file_get_contents($planFile), true, 512, JSON_THROW_ON_ERROR);
@\mkdir($out, 0777, true);
$token = \bin2hex(\random_bytes(8));
$jit = $mode === 'off' ? ['-d', 'opcache.jit=disable'] : ['-d', 'opcache.jit=tracing', '-d', 'opcache.jit_buffer_size=64M'];
# Without address randomization: libc string functions count differently with another alignment.
$norand = \trim((string) \shell_exec('setarch "$(uname -m)" -R true 2>&1 && echo ok')) === 'ok' ? ['setarch', \php_uname('m'), '-R'] : [];
$command = [
    ...$norand,
    'valgrind', '--tool=callgrind', '--smc-check=all-non-file', '--dump-before=usleep', "--callgrind-out-file={$out}/cg.%p",
    'php', '-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1', ...$jit, '-d', 'opcache.file_cache=',
    '-d', 'opcache.file_update_protection=0', '-d', 'opcache.optimization_level=0x7FFEBFFF', '-d', 'zend.assertions=-1',
    '-d', 'memory_limit=512M', '-d', 'display_errors=0', '-d', 'log_errors=0',
    $plan['worker'], $token,
];
$process = \proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', "{$out}/stderr.txt", 'w']], $pipes);
\is_resource($process) or exit(1);

$read = static function () use ($pipes, $token): array {
    while (($line = \fgets($pipes[1])) !== false) {
        if (\str_starts_with($line, $token . ' ')) {
            return \json_decode(\substr($line, \strlen($token) + 1), true, 512, JSON_THROW_ON_ERROR);
        }
    }

    throw new RuntimeException('The worker ended without an answer.');
};
$request = static function (array $request) use ($pipes, $read): array {
    \fwrite($pipes[0], \json_encode($request, JSON_THROW_ON_ERROR) . "\n");
    \fflush($pipes[0]);

    return $read();
};

$responses = ['ready' => $read()];
$responses['load'] = $request(['cmd' => 'load', 'files' => [$plan['file']], 'autoload' => null]);
$responses['cost'] = [];
foreach ($plan['requests'] as $r) {
    $responses['cost'][] = $request(['cmd' => 'cost', 'target' => $r['target'], 'input' => $r['input'], 'warm' => $plan['warm'], 'n1' => $plan['n1'], 'n2' => $plan['n2'], 'baseline' => true]);
}

$request(['cmd' => 'shutdown']);
\proc_close($process);
\file_put_contents("{$out}/responses.json", \json_encode($responses, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
