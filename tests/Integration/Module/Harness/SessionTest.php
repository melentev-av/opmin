<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Module\Harness;

use Opmin\Module\Harness\HarnessException;
use Opmin\Module\Harness\Session;
use Opmin\Module\Harness\WorkerOptions;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Worker lifecycle: a dead or hung worker is a result, the next call gets a new one.
 */
#[Test]
#[Covers(Session::class)]
final class SessionTest
{
    private const CODE = <<<'PHP'
        <?php
        function counter() { static $n = 0; return ++$n; }
        function leave() { exit(1); }
        function spin() { while (true) {} }
        function ok() { return 'ok'; }
        function version() { return 'v1'; }
        PHP;

    private string $dir;

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-session-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0777, true);
        \file_put_contents("{$this->dir}/code.php", self::CODE);
        \file_put_contents("{$this->dir}/v2.php", \str_replace("'v1'", "'v2'", self::CODE));
    }

    #[AfterTest]
    public function removeProject(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function exitRestartsTheWorkerForTheNextCall(): void
    {
        $session = $this->session();

        $exit = $session->call(self::call('leave'));
        $next = $session->call(self::call('ok'));

        Assert::same($exit['status'], 'exited');
        Assert::same($next['calls'][0]['value'], ['type' => 'string', 'value' => 'ok']);
        Assert::same($session->starts(), 2);
    }

    public function timeoutIsAResult(): void
    {
        $session = $this->session(new WorkerOptions(timeoutMs: 300));

        $result = $session->call(self::call('spin'));
        $next = $session->call(self::call('ok'));

        Assert::same($result['status'], 'timeout');
        Assert::same($next['status'], 'done');
    }

    public function staticVariablesSurviveInOneWorkerOnly(): void
    {
        $shared = $this->session();
        $fresh = $this->session(fresh: true);

        $shared->call(self::call('counter'));
        $second = $shared->call(self::call('counter'));
        $fresh->call(self::call('counter'));
        $isolated = $fresh->call(self::call('counter'));

        Assert::same($second['calls'][0]['value'], ['type' => 'int', 'value' => 2]);
        Assert::same($isolated['calls'][0]['value'], ['type' => 'int', 'value' => 1]);
    }

    public function workerIsReplacedAfterMaxRequests(): void
    {
        $session = $this->session(maxRequests: 2);

        foreach (\range(1, 5) as $_) {
            $session->call(self::call('ok'));
        }

        Assert::same($session->starts(), 3);
    }

    public function callsTwoVersionsAtOnce(): void
    {
        $original = $this->session();
        $changed = $this->session(overrides: ["{$this->dir}/code.php" => "{$this->dir}/v2.php"]);

        [$a, $b] = Session::callBoth($original, $changed, self::call('version'));

        Assert::same($a['calls'][0]['value']['value'], 'v1');
        Assert::same($b['calls'][0]['value']['value'], 'v2');
    }

    public function loadFailureIsAHarnessError(): never
    {
        $session = new Session(TestPhp::binary(), ['files' => ["{$this->dir}/missing.php"]], new WorkerOptions(timeoutMs: 10000));

        Expect::exception(HarnessException::class)->withMessageContaining('could not load the code');

        $session->call(self::call('ok'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function call(string $function): array
    {
        return ['target' => ['kind' => 'function', 'name' => $function], 'input' => ['args' => []]];
    }

    /**
     * @param array<string, string> $overrides
     * @param positive-int $maxRequests
     */
    private function session(?WorkerOptions $options = null, bool $fresh = false, int $maxRequests = 500, array $overrides = []): Session
    {
        return new Session(
            TestPhp::binary(),
            ['files' => ["{$this->dir}/code.php"], 'overrides' => $overrides],
            $options ?? new WorkerOptions(timeoutMs: 10000),
            $maxRequests,
            $fresh,
        );
    }
}
