<?php

declare(strict_types=1);

namespace Opmin\Module\Verification;

use Internal\Path;
use Opmin\Module\Analysis\Flag;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Config\Schema\CoverageDriver;
use Opmin\Module\Config\Schema\Verification as Config;
use Opmin\Module\Harness\HarnessException;
use Opmin\Module\Harness\Session;
use Opmin\Module\Harness\WorkerOptions;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Verification\Compare\ComparisonPolicy;
use Opmin\Module\Verification\Compare\ResultComparator;
use Opmin\Module\Verification\Coverage\Instrumenter;
use Opmin\Module\Verification\Input\ClassInfoProvider;
use Opmin\Module\Verification\Input\Feedback;
use Opmin\Module\Verification\Input\InputPlanner;
use Opmin\Module\Verification\Input\LiteralPool;
use Opmin\Module\Verification\Input\RecipeShrinker;
use Opmin\Module\Verification\Input\Signature;
use Opmin\Module\Verification\Input\TypeParser;
use Opmin\Module\Verification\Input\ValueGenerator;
use Opmin\Module\Verification\Property\Core\CorePropertyRunner;
use Opmin\Module\Verification\Property\Discard;
use Opmin\Module\Verification\Property\PropertyOutcome;
use Opmin\Module\Verification\Property\PropertyRunner;
use Opmin\Module\Verification\Property\PropertySpec;
use Opmin\Module\Verification\Target\Target;
use Opmin\Module\Verification\Target\TargetLocator;
use Opmin\Module\Verification\Target\UnsupportedTarget;

/**
 * Differential test of one function (brief, Модуль 2, level 3): the original and the changed version
 * run in two harness workers, every input goes to both, results are compared.
 *
 * Per input: both versions twice (the original must agree with itself — otherwise the function is
 * nondeterministic — and so must the changed one), then once more with warnings thrown as
 * `ErrorException` when any warning occurred. An input the original cannot take (it hangs, the input
 * cannot be built) is discarded. Inputs: the plan of boundary values and literals, random inputs,
 * then coverage-guided mutations within `fuzz_time_ms` while branches of the original stay uncovered.
 * A difference is shrunk to a minimal counterexample.
 *
 * @internal
 */
final class DiffTester
{
    /** Fixed clock of the fakes (`time()` in the analyzed code). */
    private const CLOCK = 1700000000.123456;

    /** Inputs per round of the coverage-guided search. */
    private const FUZZ_ROUND = 25;

    public function __construct(
        private readonly PhpBinary $php,
        private readonly Config $config,
        private readonly Path $workDir,
        private readonly ?Path $corpus = null,
        private readonly PropertyRunner $runner = new CorePropertyRunner(),
        private readonly TargetLocator $locator = new TargetLocator(),
        private readonly Instrumenter $instrumenter = new Instrumenter(),
    ) {}

    public function verify(DiffTask $task): Verdict
    {
        $start = \hrtime(true);
        $verdict = $this->run($task);

        return new Verdict(
            $verdict->key,
            $verdict->status,
            $verdict->reason,
            $verdict->coverage,
            $verdict->inputs,
            $verdict->probes,
            $verdict->counterexample,
            $verdict->flags,
            (float) (\hrtime(true) - $start) / 1e9,
        );
    }

    /**
     * @param array{array<string, mixed>, array<string, mixed>} $requests
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private static function both(Session $original, Session $changed, array $requests): array
    {
        $original->begin($requests[0]);
        $changed->begin($requests[1]);

        return [$original->end(), $changed->end()];
    }

    /**
     * @param array<string, mixed> $function
     */
    private static function signatureKey(array $function): string
    {
        return (string) \json_encode([$function['params'] ?? null, $function['return'] ?? null]);
    }

    /**
     * @param array<string, mixed> $result
     * @return list<int>
     */
    private static function probes(array $result): array
    {
        /** @var list<int> */
        return \is_array($result['probes'] ?? null) ? $result['probes'] : [];
    }

    /**
     * Executed lines of the function (Xdebug, pcov): the first result tells how many lines it has.
     *
     * @param array<string, mixed> $result
     * @param array{int, int} $range
     * @return list<int>
     */
    private static function lines(array $result, string $file, array $range, Feedback $feedback): array
    {
        /** @var array<string, array{executed: list<int>, executable: list<int>}> $lines */
        $lines = \is_array($result['lines'] ?? null) ? $result['lines'] : [];
        $data = $lines[$file] ?? $lines[(string) \realpath($file)] ?? null;
        if ($data === null) {
            return [];
        }

        $inside = static fn(int $line): bool => $line >= $range[0] && $line <= $range[1];
        $executable = \array_values(\array_filter($data['executable'], $inside));
        $feedback->probes() === 0 && $executable !== [] and $feedback->resize($executable);

        return \array_values(\array_filter($data['executed'], $inside));
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function hasErrors(array $result): bool
    {
        /** @var list<array<string, mixed>> $calls */
        $calls = \is_array($result['calls'] ?? null) ? $result['calls'] : [];
        foreach ($calls as $call) {
            /** @var list<array<string, mixed>> $errors */
            $errors = \is_array($call['errors'] ?? null) ? $call['errors'] : [];
            foreach ($errors as $error) {
                if (($error['suppressed'] ?? false) !== true) {
                    return true;
                }
            }
        }

        return false;
    }

    private function run(DiffTask $task): Verdict
    {
        $originalCode = $task->original;
        try {
            $original = $this->locator->locate($task->original, $task->key, $task->relative);
            $changed = $this->locator->locate($task->changed, $task->key, $task->relative);
        } catch (UnsupportedTarget $e) {
            return new Verdict($task->key, VerdictStatus::Unverified, 'cannot be called: ' . $e->getMessage());
        }

        $flags = $original->flags;
        if (\in_array(Flag::Eval, $flags, true) || \in_array(Flag::Include, $flags, true)) {
            return new Verdict($task->key, VerdictStatus::Skipped, 'eval/include: code loaded at run time sees the local scope', flags: $flags);
        }

        $lines = \in_array($this->config->coverageDriver, [CoverageDriver::Xdebug, CoverageDriver::Pcov], true);
        $probes = 0;
        $probed = $original;
        if (!$lines) {
            [$originalCode, $probes] = $this->instrumenter->instrument($task->original, $original->node);
            # The closure wrapper must carry the probes too.
            $probed = $this->locator->locate($originalCode, $task->key, $task->relative);
        }

        $dir = $this->workDir->join('verify-' . \bin2hex(\random_bytes(6)));
        FS::mkdir((string) $dir);
        $static = \in_array(Flag::StaticVar, $flags, true);
        $options = new WorkerOptions(
            memoryLimit: $this->config->memoryLimit,
            timeoutMs: $this->config->callTimeoutMs,
            coverage: $lines ? $this->config->coverageDriver->value : null,
        );
        $originalSession = new Session($this->php, $this->load($task, $probed, $originalCode, $dir, 'original', $lines), $options, fresh: $static);
        $changedSession = new Session($this->php, $this->load($task, $changed, $task->changed, $dir, 'changed', false), new WorkerOptions(
            memoryLimit: $this->config->memoryLimit,
            timeoutMs: $this->config->callTimeoutMs,
        ), fresh: $static);

        try {
            return $this->test($task, $probed, $changed, $originalSession, $changedSession, $probes, $lines, $static);
        } catch (HarnessException $e) {
            return new Verdict($task->key, VerdictStatus::Unverified, 'the harness cannot run the function: ' . $e->getMessage(), flags: $flags);
        } finally {
            $originalSession->close();
            $changedSession->close();
            FS::remove($dir);
        }
    }

    /**
     * @param non-negative-int $probes
     */
    private function test(DiffTask $task, Target $original, Target $changed, Session $originalSession, Session $changedSession, int $probes, bool $lines, bool $static): Verdict
    {
        $describedOriginal = $this->describe($originalSession, $original);
        $describedChanged = $this->describe($changedSession, $changed);
        $parser = $this->parser($task->original, $original);
        $signature = Signature::fromDescribe($describedOriginal, $parser, $original->receiver);
        $signatureChanged = self::signatureKey($describedOriginal) !== self::signatureKey($describedChanged);

        $classes = new class($originalSession) implements ClassInfoProvider {
            public function __construct(private readonly Session $session) {}

            public function info(string $class): ?array
            {
                /** @var array<string, mixed>|null */
                return $this->session->query(['cmd' => 'class', 'name' => $class])['class'] ?? null;
            }
        };

        $feedback = new Feedback($probes);
        $literals = LiteralPool::collect($original->node, $changed->node);
        if (\in_array(Flag::Time, $original->flags, true) || \in_array(Flag::Time, $changed->flags, true)) {
            $now = (int) self::CLOCK;
            $literals->addInts([$now, $now + 60, $now - 60, $now + 3600, $now + 86400, $now - 86400]);
        }

        $planner = new InputPlanner($signature, new ValueGenerator($classes, $literals), $feedback, mixStrict: $signatureChanged);
        $shrinker = new RecipeShrinker($signature->required());
        $comparator = new ResultComparator(new ComparisonPolicy($this->config->warnings, $this->config->floatTolerance));
        $checked = 0;
        $range = [(int) ($describedOriginal['line'] ?? 0), (int) ($describedOriginal['end_line'] ?? 0)];
        $file = (string) $task->file;
        $check = static function (Input $input) use ($original, $changed, $originalSession, $changedSession, $comparator, $feedback, $static, $lines, $range, $file, &$checked): void {
            $repeat = $static ? 3 : 1;
            $requests = [
                ['target' => $original->call, 'input' => $input->toArray(), 'errors' => 'record', 'repeat' => $repeat],
                ['target' => $changed->call, 'input' => $input->toArray(), 'errors' => 'record', 'repeat' => $repeat],
            ];
            [$o1, $c1] = self::both($originalSession, $changedSession, $requests);
            if (\in_array($o1['status'] ?? null, ['unbuildable', 'timeout', 'crashed'], true)) {
                # The original cannot take this input: nothing to compare.
                throw new Discard();
            }

            [$o2, $c2] = self::both($originalSession, $changedSession, $requests);
            $noise = $comparator->compare($o1, $o2);
            if ($noise !== null) {
                throw new Nondeterminism($noise, 'original');
            }

            $feedback->record($input, $lines ? self::lines($o1, $file, $range, $feedback) : self::probes($o1));
            $difference = $comparator->compare($o1, $c1) ?? $comparator->compare($c1, $c2);
            $changedResult = $c1;
            if ($difference === null && (self::hasErrors($o1) || self::hasErrors($c1))) {
                # Warnings as exceptions, like the error handlers of Laravel and Symfony.
                $requests[0]['errors'] = $requests[1]['errors'] = 'throw';
                [$ot, $ct] = self::both($originalSession, $changedSession, $requests);
                $difference = $comparator->compare($ot, $ct);
                [$o1, $changedResult] = [$ot, $ct];
            }

            $difference === null or throw new Mismatch($difference, $o1, $changedResult);
            ++$checked;
        };

        $id = $task->relative . '::' . $task->key;
        $seed = $this->config->seed;
        $outcome = $this->runner->run(
            new PropertySpec($id, \max(1, \count($planner->plan()) + $this->config->randomInputs), $seed, corpus: $this->corpus),
            $planner,
            $shrinker,
            $check,
        );

        $deadline = \hrtime(true) + $this->config->fuzzTimeMs * 1_000_000;
        for ($round = 1; !$outcome->isFalsified() && !$feedback->complete() && \hrtime(true) < $deadline; ++$round) {
            $planner->fuzz();
            $left = \max(1, \intdiv($deadline - \hrtime(true), 1_000_000));
            $outcome = $this->runner->run(
                new PropertySpec($id, self::FUZZ_ROUND, $seed + $round, budgetMs: $left),
                $planner,
                $shrinker,
                $check,
            );
        }

        return $this->verdict($task, $original, $outcome, $feedback, $checked);
    }

    private function verdict(DiffTask $task, Target $original, PropertyOutcome $outcome, Feedback $feedback, int $checked): Verdict
    {
        $flags = $original->flags;
        $coverage = $feedback->percent();
        $checked = \max(0, $checked);
        $base = static fn(VerdictStatus $status, string $reason, ?Counterexample $counterexample = null): Verdict => new Verdict(
            $task->key,
            $status,
            $reason,
            $coverage,
            $checked,
            $feedback->probes(),
            $counterexample,
            $flags,
        );

        if ($outcome->isFalsified()) {
            $failure = $outcome->failure;
            if ($failure instanceof Mismatch) {
                return $base(VerdictStatus::Mismatch, (string) $failure->difference, new Counterexample(
                    $outcome->shrunk,
                    $failure->difference,
                    $failure->original,
                    $failure->changed,
                    $outcome->seed,
                    $outcome->shrinkSteps,
                    $outcome->flaky,
                ));
            }

            return $base(VerdictStatus::Unverified, match (true) {
                $failure instanceof Nondeterminism => 'nondeterministic: ' . $failure->getMessage(),
                $failure instanceof HarnessException => 'the harness failed: ' . $failure->getMessage(),
                default => 'the differential test failed: ' . $failure::class . ': ' . $failure->getMessage(),
            });
        }

        if ($checked === 0) {
            return $base(VerdictStatus::Unverified, 'no input could be checked (the original rejects or hangs on all of them)');
        }

        if ($outcome->gaveUp) {
            return $base(VerdictStatus::Unverified, 'too many inputs were discarded');
        }

        $effects = \array_values(\array_filter($flags, static fn(Flag $f): bool => $f === Flag::Io || $f === Flag::Global));
        if ($effects !== []) {
            return $base(VerdictStatus::Unverified, 'side effects (' . \implode(', ', Flag::values($effects)) . '): only the project\'s tests can prove the change');
        }

        if ($coverage < (float) $this->config->minBranchCoverage) {
            return $base(VerdictStatus::Unverified, \sprintf(
                'differential tests cover %s%% of the branches of the original, %d%% required (verification.min_branch_coverage)',
                $coverage,
                $this->config->minBranchCoverage,
            ));
        }

        return $base(VerdictStatus::Equivalent, '');
    }

    /**
     * @return array<string, mixed>
     */
    private function load(DiffTask $task, Target $target, string $code, Path $dir, string $version, bool $coverage): array
    {
        $copy = $dir->join("{$version}.php");
        \file_put_contents((string) $copy, $code);
        $overrides = [(string) $task->file => (string) $copy];
        $i = 0;
        foreach ($task->otherChanges as $path => [$before, $after]) {
            $other = $dir->join("{$version}-" . ++$i . '.php');
            \file_put_contents((string) $other, $version === 'original' ? $before : $after);
            $overrides[$path] = (string) $other;
        }

        $files = [(string) $task->file];
        if ($target->wrapper !== null) {
            $wrapper = $dir->join("{$version}-wrapper.php");
            \file_put_contents((string) $wrapper, $target->wrapper);
            $files[] = (string) $wrapper;
        }

        $load = [
            'autoload' => $task->autoload === null ? null : (string) $task->autoload,
            'files' => $files,
            'overrides' => $overrides,
            'fakes' => ['namespaces' => $target->namespace === '' ? [] : [$target->namespace], 'time' => self::CLOCK, 'seed' => $this->config->seed],
        ];
        $coverage and $load['coverage'] = $this->config->coverageDriver->value;

        return $load;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Session $session, Target $target): array
    {
        /** @var array<string, mixed> */
        return $session->query(['cmd' => 'describe', 'target' => $target->call])['function'] ?? [];
    }

    /**
     * Resolves class names of the function's phpdoc against its namespace and `use` imports.
     */
    private function parser(string $code, Target $target): TypeParser
    {
        $uses = [];
        if (\preg_match_all('/^\s*use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/mi', $code, $m, \PREG_SET_ORDER) > 0) {
            foreach ($m as $use) {
                $alias = $use[2] ?? '';
                $alias === '' and $alias = \substr($use[1], ((int) \strrpos($use[1], '\\')) + (\str_contains($use[1], '\\') ? 1 : 0));
                $uses[\strtolower($alias)] = \ltrim($use[1], '\\');
            }
        }

        $namespace = $target->namespace;

        return new TypeParser(static function (string $name) use ($uses, $namespace): string {
            if (\str_starts_with($name, '\\')) {
                return \ltrim($name, '\\');
            }

            $first = \strtolower(\explode('\\', $name)[0]);
            if (isset($uses[$first])) {
                return $uses[$first] . \substr($name, \strlen($first));
            }

            return $namespace === '' ? $name : "{$namespace}\\{$name}";
        }, $target->receiver ?? (isset($target->call['class']) ? (string) $target->call['class'] : null));
    }
}
