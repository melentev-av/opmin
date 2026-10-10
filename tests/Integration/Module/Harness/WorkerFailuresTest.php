<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Module\Harness;

use Opmin\Module\Harness\HarnessException;
use Opmin\Module\Harness\Session;
use Opmin\Module\Harness\Worker;
use Opmin\Module\Harness\WorkerException;
use Opmin\Module\Harness\WorkerOptions;
use Opmin\Module\Php\PhpBinary;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The client side of broken workers: a php.binary that is not the harness, a harness that breaks
 * the protocol or dies, a call the harness refuses.
 */
#[Test]
#[Covers(Worker::class)]
#[Covers(Session::class)]
final class WorkerFailuresTest
{
    private string $dir;

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-worker-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0777, true);
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function binaryThatIsNotTheHarnessDoesNotStart(): never
    {
        $worker = new Worker($this->fake('echo "PHP 8.4.0 (cli)"; sleep 1'), new WorkerOptions(loadTimeoutMs: 3000));

        Expect::exception(WorkerException::class)->withMessageContaining('ended without an answer');

        $worker->start();
    }

    public function readyLineMustSayReady(): never
    {
        $worker = new Worker($this->fake('echo "$token {\"ok\":true}"; sleep 1'), new WorkerOptions(loadTimeoutMs: 3000));

        Expect::exception(WorkerException::class)->withMessageContaining('did not report ready');

        $worker->start();
    }

    public function invalidJsonIsAProtocolError(): void
    {
        $worker = new Worker($this->fake('echo "$token {broken"; sleep 1'), new WorkerOptions(loadTimeoutMs: 3000));

        try {
            $worker->start();
            Assert::fail('No exception');
        } catch (WorkerException $e) {
            Assert::same($e->reason, WorkerException::PROTOCOL);
            Assert::string($e->getMessage())->contains("invalid response: {$this->tokenOf($e)}");
        }
    }

    public function deathKeepsTheTailOfStderrAndTheExitCode(): void
    {
        $worker = new Worker($this->fake('head -c 9000 /dev/zero | tr "\\0" a >&2; printf "b" >&2; exit 3'), new WorkerOptions(loadTimeoutMs: 3000));

        try {
            $worker->start();
            Assert::fail('No exception');
        } catch (WorkerException $e) {
            Assert::same($e->reason, WorkerException::CRASH);
            Assert::same(\strlen($e->stderr), 8192);
            Assert::true(\str_ends_with($e->stderr, 'ab'));
            Assert::string($e->getMessage())->startsWith('The harness ended without an answer (exit code 3).');
        }
    }

    public function aLongRequestDoesNotDeadlockWithOutputOfTheWorker(): void
    {
        # The worker floods stdout (a buffered php://output flushed by a destructor) before it reads the
        # next request, while the request is longer than a pipe buffer: both sides would wait forever.
        $worker = new Worker($this->fake(<<<'SH'
            echo "$token {\"ok\":true,\"ready\":true}"
            head -c 1048576 /dev/zero | tr "\0" x; echo
            read line
            echo "$token {\"ok\":true,\"length\":${#line}}"
            sleep 1
            SH), new WorkerOptions(timeoutMs: 10000, loadTimeoutMs: 3000));
        $worker->start();

        $response = $worker->request(['cmd' => 'ping', 'pad' => \str_repeat('a', 300000)]);

        Assert::true($response['ok']);
        Assert::true($response['length'] > 300000);
    }

    public function stoppedWorkerRefusesRequests(): void
    {
        $worker = new Worker(TestPhp::binary(), new WorkerOptions(loadTimeoutMs: 30000, timeoutMs: 10000));
        $worker->start();
        $worker->request(['cmd' => 'ping']);
        $requests = $worker->requests();

        $worker->stop();

        Assert::same($requests, 1);
        Assert::false($worker->isRunning());
        Expect::exception(WorkerException::class)->withMessageContaining('not running');
        $worker->send(['cmd' => 'ping']);
    }

    public function harnessRefusingACallIsAnError(): void
    {
        $ready = 'echo "$token {\\"ok\\":true,\\"ready\\":true}"';
        $answer = static fn(string $json): string => 'read line; echo "$token ' . \addcslashes($json, '"') . '"';
        $refusing = new Session($this->fake("{$ready}\n{$answer('{"ok":true}')}\n{$answer('{"ok":false,"error":"boom"}')}\nsleep 1"), ['files' => []], new WorkerOptions(loadTimeoutMs: 3000));
        $silent = new Session($this->fake("{$ready}\n{$answer('{"ok":true}')}\n{$answer('{"ok":false}')}\nsleep 1"), ['files' => []], new WorkerOptions(loadTimeoutMs: 3000));
        $badLoad = new Session($this->fake("{$ready}\n{$answer('{"ok":false,"error":"no autoload"}')}\nsleep 1"), ['files' => []], new WorkerOptions(loadTimeoutMs: 3000));

        foreach ([[$refusing, 'The harness refused the call: boom'], [$silent, 'The harness refused the call: no reason given'], [$badLoad, 'The harness could not load the code: no autoload']] as [$session, $message]) {
            try {
                $session->call(['target' => [], 'input' => []]);
                Assert::fail('No exception');
            } catch (HarnessException $e) {
                Assert::same($e->getMessage(), $message);
            }
        }
    }

    public function harnessRefusingAQueryIsAnError(): never
    {
        $session = new Session(TestPhp::binary(), ['files' => []], new WorkerOptions(timeoutMs: 10000));

        Expect::exception(HarnessException::class)->withMessageContaining('refused the request: ReflectionException');

        $session->query(['cmd' => 'describe', 'target' => ['kind' => 'function', 'name' => 'nope']]);
    }

    public function endWithoutBeginIsAnError(): never
    {
        Expect::exception(HarnessException::class)->withMessage('No call in progress.');

        (new Session(TestPhp::binary(), ['files' => []]))->end();
    }

    public function closedSessionStartsAgainAndTimeoutsCarryTheReason(): void
    {
        \file_put_contents($this->dir . '/spin.php', "<?php\nfunction spin() { while (true) {} }\nfunction ok() { return 1; }\n");
        $session = new Session(TestPhp::binary(), ['files' => [$this->dir . '/spin.php']], new WorkerOptions(timeoutMs: 300));
        $session->call(['target' => ['kind' => 'function', 'name' => 'ok'], 'input' => ['args' => []]]);

        $session->close();
        $after = $session->call(['target' => ['kind' => 'function', 'name' => 'ok'], 'input' => ['args' => []]]);
        $timeout = $session->call(['target' => ['kind' => 'function', 'name' => 'spin'], 'input' => ['args' => []]]);

        Assert::same($session->starts(), 2);
        Assert::same($after['status'], 'done');
        Assert::same([$timeout['ok'], $timeout['status']], [true, 'timeout']);
        Assert::string((string) $timeout['error'])->contains('did not answer in 300 ms');
    }

    /**
     * A php.binary that runs a shell snippet; `$token` is the last argument (the session token).
     */
    private function fake(string $script): PhpBinary
    {
        $path = $this->dir . '/php-' . \bin2hex(\random_bytes(3));
        \file_put_contents($path, "#!/bin/sh\nfor token; do :; done\n{$script}\n");
        \chmod($path, 0755);

        return new PhpBinary($path, '8.4.0', 80400, [], []);
    }

    private function tokenOf(WorkerException $e): string
    {
        \preg_match('/invalid response: (\S+)/', $e->getMessage(), $m);

        return $m[1] ?? '?';
    }
}
