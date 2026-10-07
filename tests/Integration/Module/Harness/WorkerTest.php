<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Module\Harness;

use Opmin\Module\Harness\HarnessFiles;
use Opmin\Module\Harness\Worker;
use Opmin\Module\Harness\WorkerException;
use Opmin\Module\Harness\WorkerOptions;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The harness (`harness/`) under php.binary from {@see TestPhp}, driven through the orchestrator's client.
 */
#[Test]
#[Covers(Worker::class)]
#[Covers(HarnessFiles::class)]
final class WorkerTest
{
    private const CODE = <<<'PHP'
        <?php
        namespace App;

        interface Clock { public function now(): int; public function log(string $message): void; }

        final class Money
        {
            public function __construct(public readonly int $amount, private string $currency = 'EUR') {}
            public function currency(): string { return $this->currency; }
        }

        class Counter
        {
            public static int $total = 0;
            private int $n = 0;
            private function add(int $by): int { self::$total += $by; return $this->n += $by; }
        }

        function swap(&$a, &$b) { [$a, $b] = [$b, $a]; echo 'swapped'; return $a . $b; }
        function warn(array $a) { return $a['missing'] ?? @$a['quiet'] . $a['loud']; }
        function clock(Clock $c) { $c->log('start'); return $c->now() + $c->now(); }
        function typed(int $n) { return $n * 2; }
        function now() { return [time(), random_int(1, 1000000), uniqid()]; }
        function where() { return __FILE__; }
        function leave() { echo 'bye'; exit(3); }
        function hog() { $a = []; while (true) { $a[] = str_repeat('x', 1 << 20); } }
        function spin() { while (true) {} }
        function stray() { fwrite(STDOUT, "not a response\n"); return 1; }
        function setGlobal() { $GLOBALS['opmin_test'] = 5; return 1; }
        function gen() { yield 'a' => 1; yield 'b' => 2; return 3; }
        function probe($x) { if ($x) { \Opmin\Harness\Probe::hit(1); return 'yes'; } \Opmin\Harness\Probe::hit(2); return 'no'; }
        function fail() { throw new \DomainException('bad', 7, new \RuntimeException('cause')); }
        function closures(int $k) { return function (int $x) use ($k) { return $x * $k; }; }
        function cwd() { return getcwd(); }
        PHP;

    private string $dir;
    private ?Worker $worker = null;

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-harness-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0777, true);
        \file_put_contents("{$this->dir}/code.php", self::CODE);
        \file_put_contents("{$this->dir}/other.php", "<?php\nnamespace App;\nfunction where() { return 'override:' . __FILE__; }\n");
        \file_put_contents("{$this->dir}/wrappers.php", "<?php\nnamespace App;\nfunction __opmin_closure_1(\$k) { return function (int \$x) use (\$k) { return \$x * \$k; }; }\n");
    }

    #[AfterTest]
    public function removeProject(): void
    {
        $this->worker?->stop();
        $this->worker = null;
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function answersPingWithItsPhp(): void
    {
        $ready = $this->worker()->start();

        $pong = $this->worker()->request(['cmd' => 'ping']);

        Assert::same($ready['php'], TestPhp::binary()->version);
        Assert::same($pong['ok'], true);
    }

    public function overrideServesContentUnderTheRealPath(): void
    {
        $this->load(overrides: ["{$this->dir}/code.php" => "{$this->dir}/other.php"]);

        $result = $this->call(['kind' => 'function', 'name' => 'App\where']);

        Assert::same($result['calls'][0]['value'], ['type' => 'string', 'value' => "override:{$this->dir}/code.php"]);
    }

    public function describesByRefArgumentsAndOutput(): void
    {
        $this->load();

        $result = $this->call(['kind' => 'function', 'name' => 'App\swap'], [self::int(1), self::string('x')]);

        Assert::same($result['calls'][0]['value'], self::string('x1'));
        Assert::same($result['calls'][0]['output'], self::string('swapped'));
        Assert::same($result['args'], [self::string('x'), self::int(1)]);
    }

    public function recordsWarningsWithSuppression(): void
    {
        $this->load();

        $result = $this->call(['kind' => 'function', 'name' => 'App\warn'], [['type' => 'array', 'items' => []]]);

        $errors = $result['calls'][0]['errors'];
        Assert::same(\array_column($errors, 'message'), ['Undefined array key "quiet"', 'Undefined array key "loud"']);
        Assert::same(\array_column($errors, 'suppressed'), [true, false]);
        Assert::same(\array_column($errors, 'level'), ['E_WARNING', 'E_WARNING']);
    }

    public function throwModeTurnsWarningsIntoErrorException(): void
    {
        $this->load();

        $result = $this->call(['kind' => 'function', 'name' => 'App\warn'], [['type' => 'array', 'items' => []]], errors: 'throw');

        Assert::same($result['calls'][0]['status'], 'threw');
        Assert::same($result['calls'][0]['exception']['class'], 'ErrorException');
        Assert::same($result['calls'][0]['exception']['message'], 'Undefined array key "loud"');
    }

    public function journalsMockCallsInOrder(): void
    {
        $this->load();
        $mock = ['type' => 'mock', 'interface' => 'App\Clock', 'id' => 1, 'returns' => ['now' => [self::int(5), self::int(6)]]];

        $result = $this->call(['kind' => 'function', 'name' => 'App\clock'], [$mock]);

        Assert::same($result['calls'][0]['value'], self::int(11));
        Assert::same(\array_column($result['mocks'], 'method'), ['log', 'now', 'now']);
        Assert::same($result['mocks'][0]['args'], [self::string('start')]);
    }

    public function callerDecidesTypeCoercion(): void
    {
        $this->load();
        $target = ['kind' => 'function', 'name' => 'App\typed'];

        $weak = $this->call($target, [self::string('5')]);
        $strict = $this->call($target, [self::string('5')], strict: true);

        Assert::same($weak['calls'][0]['value'], self::int(10));
        Assert::same($strict['calls'][0]['exception']['class'], 'TypeError');
    }

    public function callsPrivateMethodOnObjectBuiltFromProperties(): void
    {
        $this->load();
        $counter = ['type' => 'object', 'class' => 'App\Counter', 'via' => 'props', 'props' => ['n' => self::int(10)]];

        $result = $this->call(['kind' => 'method', 'class' => 'App\Counter', 'name' => 'add'], [self::int(5)], receiver: $counter);
        $again = $this->call(['kind' => 'method', 'class' => 'App\Counter', 'name' => 'add'], [self::int(5)], receiver: $counter);

        Assert::same($result['calls'][0]['value'], self::int(15));
        Assert::same($result['this']['props'], [['App\Counter::n', self::int(15)]]);
        Assert::same($result['statics'], ['App\Counter::$total' => self::int(5)]);
        # Static properties are restored after each call.
        Assert::same($again['statics'], ['App\Counter::$total' => self::int(5)]);
    }

    public function buildsReadonlyObjectsAndRefs(): void
    {
        $this->load();
        $money = ['type' => 'object', 'class' => 'App\Money', 'via' => 'ctor', 'args' => [self::int(3)], 'id' => 1];

        $result = $this->call(['kind' => 'method', 'class' => 'App\Money', 'name' => 'currency'], [['type' => 'ref', 'id' => 1]], receiver: $money);

        Assert::same($result['calls'][0]['value'], self::string('EUR'));
        Assert::same($result['args'][0], ['type' => 'object', 'class' => 'App\Money', 'id' => 1, 'props' => [['amount', self::int(3)], ['App\Money::currency', self::string('EUR')]]]);
        Assert::same($result['this'], $result['args'][0]);
    }

    public function fakesTimeAndRandomnessDeterministically(): void
    {
        $this->load();

        $first = $this->call(['kind' => 'function', 'name' => 'App\now']);
        $second = $this->call(['kind' => 'function', 'name' => 'App\now']);

        Assert::same($first['calls'][0]['value'], $second['calls'][0]['value']);
        Assert::same($first['calls'][0]['value']['items'][0][1], self::int(1700000000));
    }

    public function describesExceptionsWithPrevious(): void
    {
        $this->load();

        $result = $this->call(['kind' => 'function', 'name' => 'App\fail']);

        $exception = $result['calls'][0]['exception'];
        Assert::same([$exception['class'], $exception['message'], $exception['code']], ['DomainException', 'bad', 7]);
        Assert::same($exception['previous']['message'], 'cause');
    }

    public function iteratesReturnedGenerators(): void
    {
        $this->load();

        $result = $this->call(['kind' => 'function', 'name' => 'App\gen']);

        Assert::same($result['calls'][0]['value']['items'], [[self::string('a'), self::int(1)], [self::string('b'), self::int(2)]]);
        Assert::same($result['calls'][0]['value']['return'], self::int(3));
    }

    public function reportsAndRestoresGlobals(): void
    {
        $this->load();

        $result = $this->call(['kind' => 'function', 'name' => 'App\setGlobal']);
        $check = $this->worker()->request(['cmd' => 'call', 'target' => ['kind' => 'function', 'name' => 'App\setGlobal'], 'input' => ['args' => []]]);

        Assert::same($result['globals'], ['opmin_test' => self::int(5)]);
        Assert::same($check['globals'], ['opmin_test' => self::int(5)]);
    }

    public function reportsProbesHit(): void
    {
        $this->load();

        $yes = $this->call(['kind' => 'function', 'name' => 'App\probe'], [['type' => 'bool', 'value' => true]]);
        $no = $this->call(['kind' => 'function', 'name' => 'App\probe'], [['type' => 'bool', 'value' => false]]);

        Assert::same([$yes['probes'], $no['probes']], [[1], [2]]);
    }

    public function callsClosuresThroughWrappers(): void
    {
        $this->load(files: ["{$this->dir}/code.php", "{$this->dir}/wrappers.php"]);

        $result = $this->call(['kind' => 'closure', 'wrapper' => 'App\__opmin_closure_1'], [self::int(4)], uses: ['k' => self::int(3)]);

        Assert::same($result['calls'][0]['value'], self::int(12));
    }

    public function runsInTheGivenWorkingDirectory(): void
    {
        \mkdir("{$this->dir}/work");
        $this->worker = new Worker(TestPhp::binary(), new WorkerOptions(timeoutMs: 10000, cwd: "{$this->dir}/work"));
        $this->load();

        $result = $this->call(['kind' => 'function', 'name' => 'App\cwd']);

        Assert::same($result['calls'][0]['value'], self::string("{$this->dir}/work"));
    }

    public function strayStdoutIsNotAResponse(): void
    {
        $this->load();

        $result = $this->call(['kind' => 'function', 'name' => 'App\stray']);

        Assert::same($result['calls'][0]['value'], self::int(1));
    }

    public function exitIsAnsweredAndEndsTheWorker(): void
    {
        $this->load();

        $result = $this->call(['kind' => 'function', 'name' => 'App\leave']);

        Assert::same($result['status'], 'exited');
        Assert::same($result['calls'][0]['output'], self::string('bye'));
        \usleep(200_000);
        Assert::false($this->worker()->isRunning());
    }

    public function outOfMemoryIsAFatalResult(): void
    {
        $this->worker = new Worker(TestPhp::binary(), new WorkerOptions(memoryLimit: '32M', timeoutMs: 10000));
        $this->load();

        $result = $this->call(['kind' => 'function', 'name' => 'App\hog']);

        Assert::same($result['status'], 'fatal');
        Assert::string((string) $result['calls'][0]['message'])->contains('Allowed memory size');
    }

    public function unbuildableInputIsReported(): void
    {
        $this->load();

        $result = $this->call(['kind' => 'function', 'name' => 'App\typed'], [['type' => 'object', 'class' => 'App\Nope', 'via' => 'ctor', 'args' => []]]);

        Assert::same($result['status'], 'unbuildable');
    }

    public function timeoutKillsTheWorker(): never
    {
        $this->worker = new Worker(TestPhp::binary(), new WorkerOptions(timeoutMs: 300));
        $this->load();

        Expect::exception(WorkerException::class)->withMessageContaining('did not answer in 300 ms');

        $this->call(['kind' => 'function', 'name' => 'App\spin']);
    }

    /**
     * @return array{type: 'int', value: int}
     */
    private static function int(int $value): array
    {
        return ['type' => 'int', 'value' => $value];
    }

    /**
     * @return array{type: 'string', value: string}
     */
    private static function string(string $value): array
    {
        return ['type' => 'string', 'value' => $value];
    }

    private function worker(): Worker
    {
        return $this->worker ??= new Worker(TestPhp::binary(), new WorkerOptions(timeoutMs: 10000));
    }

    /**
     * @param array<string, string> $overrides
     * @param list<string>|null $files
     */
    private function load(array $overrides = [], ?array $files = null): void
    {
        $this->worker()->start();
        $response = $this->worker()->request([
            'cmd' => 'load',
            'files' => $files ?? ["{$this->dir}/code.php"],
            'overrides' => $overrides,
            'fakes' => ['namespaces' => ['App'], 'time' => 1700000000.5, 'seed' => 42],
        ], 30000);
        Assert::same($response['ok'], true);
    }

    /**
     * @param array<string, mixed> $target
     * @param list<array<string, mixed>> $args
     * @param array<string, mixed>|null $receiver
     * @param array<string, array<string, mixed>> $uses
     * @return array<string, mixed>
     */
    private function call(array $target, array $args = [], ?array $receiver = null, array $uses = [], bool $strict = false, string $errors = 'record'): array
    {
        $response = $this->worker()->request([
            'cmd' => 'call',
            'target' => $target,
            'input' => ['args' => $args, 'this' => $receiver, 'uses' => $uses, 'strict' => $strict],
            'errors' => $errors,
        ]);
        Assert::same($response['ok'], true);

        return $response;
    }
}
