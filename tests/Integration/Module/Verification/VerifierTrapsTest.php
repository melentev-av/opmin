<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Module\Verification;

use Internal\Path;
use Opmin\Module\Config\Schema\Verification;
use Opmin\Module\Verification\DiffTask;
use Opmin\Module\Verification\DiffTester;
use Opmin\Module\Verification\Verdict;
use Opmin\Module\Verification\VerdictStatus;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The verifier's acceptance table (brief, «Тестирование самой тулзы»): every trap — a rewrite that
 * changes behavior — is rejected, every equivalent pair is accepted (a verifier that rejects
 * everything would pass the traps). Runs under php.binary from {@see TestPhp}: CI runs it with
 * PHP 8.1–8.5.
 */
#[Test]
#[Covers(DiffTester::class)]
final class VerifierTrapsTest
{
    private const BAG = <<<'PHP'
        final class Bag {
            private array $data = [];
            public function __isset(string $name): bool { return isset($this->data[$name]); }
            public function __get(string $name): mixed { return $this->data[$name] ?? 'default'; }
        }
        PHP;
    private const LOG = 'interface Log { public function write(string $message): void; }';
    private const HOLDER = 'final class Node { public ?Holder $owner = null; public function bar(): int { $this->owner?->reset(); return 1; } } final class Holder { private ?Node $foo; public function __construct() { $this->foo = new Node(); $this->foo->owner = $this; } public function reset(): void { $this->foo = null; }';
    private const OFFSETS = 'final class Offsets implements \ArrayAccess { private int $reads = 0; public function offsetExists(mixed $o): bool { return true; } public function offsetGet(mixed $o): mixed { return ++$this->reads; } public function offsetSet(mixed $o, mixed $v): void {} public function offsetUnset(mixed $o): void {} }';

    private string $dir;

    /**
     * [before, after, function key]: the change must be rejected.
     *
     * @return iterable<string, array{string, string, non-empty-string}>
     */
    public static function traps(): iterable
    {
        yield '== becomes === on mixed types' => [
            'function f($a, $b) { if ($a == $b) { return "same"; } return "different"; }',
            'function f($a, $b) { if ($a === $b) { return "same"; } return "different"; }',
            'App\f',
        ];
        yield 'array_key_exists becomes isset with null values' => [
            'function f(array $a) { return array_key_exists("x", $a); }',
            'function f(array $a) { return isset($a["x"]); }',
            'App\f',
        ];
        yield 'missing key loses its warning with ??' => [
            'function f(array $a) { return $a["x"]; }',
            'function f(array $a) { return $a["x"] ?? null; }',
            'App\f',
        ];
        yield 'fully qualified \time() escapes the namespace clock mock' => [
            'function f() { return time() + 1; }',
            'function f() { return \time() + 1; }',
            'App\f',
        ];
        yield '\\time() against a deadline escapes the clock mock' => [
            'function expired(int $deadline) { return time() > $deadline; }',
            'function expired(int $deadline) { return \\time() > $deadline; }',
            'App\expired',
        ];
        yield '"unused" variable removed next to compact()' => [
            'function f($a) { $b = $a * 2; return compact("a", "b"); }',
            'function f($a) { return compact("a", "b"); }',
            'App\f',
        ];
        yield 'isset() becomes !== null on a magic object' => [
            self::BAG . ' function f(Bag $bag) { return isset($bag->x); }',
            self::BAG . ' function f(Bag $bag) { return $bag->x !== null; }',
            'App\f',
        ];
        yield 'int added to an untyped parameter without strict_types' => [
            'function f($n) { return $n === 5 ? "five" : "other"; }',
            'function f(int $n) { return $n === 5 ? "five" : "other"; }',
            'App\f',
        ];
        yield 'two calls of a mock swapped' => [
            self::LOG . ' function f(Log $log, string $m) { $log->write("start"); $log->write($m); return 1; }',
            self::LOG . ' function f(Log $log, string $m) { $log->write($m); $log->write("start"); return 1; }',
            'App\f',
        ];
        yield 'static variable differs on the second call' => [
            'function f() { static $calls = 0; $calls++; return $calls > 1 ? "again" : "first"; }',
            'function f() { static $calls = 0; return $calls++ > 1 ? "again" : "first"; }',
            'App\f',
        ];
        yield 'branch only on a magic string of the body' => [
            'function f(string $command) { if ($command === "self-destruct --force") { return "boom"; } return "ok"; }',
            'function f(string $command) { return "ok"; }',
            'App\f',
        ];
        yield '-0.0 becomes 0.0' => [
            'function f(float $x) { return $x * 1.0; }',
            'function f(float $x) { return $x == 0 ? 0.0 : $x * 1.0; }',
            'App\f',
        ];
        yield 'loop bound off by one' => [
            'function f(array $xs) { $s = 0; for ($i = 0; $i < count($xs); $i++) { $s += (int) $xs[$i]; } return $s; }',
            'function f(array $xs) { $s = 0; $n = count($xs) - 1; for ($i = 0; $i < $n; $i++) { $s += (int) $xs[$i]; } return $s; }',
            'App\f',
        ];
        yield 'by-ref argument no longer changed' => [
            'function f(array &$items, $x) { $items[] = $x; return count($items); }',
            'function f(array &$items, $x) { return count($items) + 1; }',
            'App\f',
        ];
        yield 'exception message changed' => [
            'function f(int $n) { if ($n < 0) { throw new \InvalidArgumentException("negative: $n"); } return $n; }',
            'function f(int $n) { if ($n < 0) { throw new \InvalidArgumentException("negative"); } return $n; }',
            'App\f',
        ];
        yield 'output changed' => [
            'function f(string $name) { echo "Hello, ", $name, "!"; }',
            'function f(string $name) { echo "Hello, $name"; }',
            'App\f',
        ];
        yield 'state of $this changed' => [
            'final class Counter { private int $n = 0; public function add(int $by): void { $this->n += $by; } }',
            'final class Counter { private int $n = 0; public function add(int $by): void { $this->n = $by; } }',
            'App\Counter::add',
        ];
        yield 'array keys reordered' => [
            'function f(string $a, string $b) { return ["a" => $a, "b" => $b]; }',
            'function f(string $a, string $b) { return ["b" => $b, "a" => $a]; }',
            'App\f',
        ];
        yield 'object identity lost' => [
            'final class Box { public int $v = 0; } function f(Box $box) { return [$box, $box]; }',
            'final class Box { public int $v = 0; } function f(Box $box) { return [$box, clone $box]; }',
            'App\f',
        ];

        # Rewrites opmin's own rules must never make (brief, «Ловушки для traps/ к этим правилам»):
        # each one is what a naive version of the rule would produce.
        yield 'property extracted although a call reassigns it through a back reference' => [
            self::HOLDER . ' public function run() { $a = $this->foo->bar(); $b = $this->foo?->bar(); return [$a, $b]; } }',
            self::HOLDER . ' public function run() { $foo = $this->foo; $a = $foo->bar(); $b = $foo?->bar(); return [$a, $b]; } }',
            'App\Holder::run',
        ];
        yield 'property extracted from a class whose __get counts reads' => [
            'final class Counter { private int $reads = 0; public function __get(string $n): int { return ++$this->reads; } public function twice() { return $this->value + $this->value; } }',
            'final class Counter { private int $reads = 0; public function __get(string $n): int { return ++$this->reads; } public function twice() { $value = $this->value; return $value + $value; } }',
            'App\Counter::twice',
        ];
        yield 'property extracted across a write through a reference' => [
            'final class Ref { public int $foo = 1; } function f(Ref $r) { $a = $r->foo; $ref = &$r->foo; $ref = 5; return $a + $r->foo; }',
            'final class Ref { public int $foo = 1; } function f(Ref $r) { $foo = $r->foo; $a = $foo; $ref = &$r->foo; $ref = 5; return $a + $foo; }',
            'App\f',
        ];
        yield 'property extracted from a lazy proxy that unsets it for __get' => [
            'class Entity { public string $name = "real"; } final class Proxy extends Entity { private int $loads = 0; public function __construct() { unset($this->name); } public function __get(string $p): string { return "loaded" . ++$this->loads; } public function greet() { return $this->name . ", " . $this->name; } }',
            'class Entity { public string $name = "real"; } final class Proxy extends Entity { private int $loads = 0; public function __construct() { unset($this->name); } public function __get(string $p): string { return "loaded" . ++$this->loads; } public function greet() { $name = $this->name; return $name . ", " . $name; } }',
            'App\Proxy::greet',
        ];
        yield 'element extracted from an ArrayAccess object' => [
            self::OFFSETS . ' function f(Offsets $o) { return $o["k"] + $o["k"]; }',
            self::OFFSETS . ' function f(Offsets $o) { $k = $o["k"]; return $k + $k; }',
            'App\f',
        ];
        yield 'element extracted although the key may be missing: one warning instead of two' => [
            'function f(array $a) { return [$a["k"], $a["k"]]; }',
            'function f(array $a) { $k = $a["k"]; return [$k, $k]; }',
            'App\f',
        ];
        yield 'count() hoisted out of a loop that appends to the array' => [
            '/** @param list<int> $a */ function f(array $a) { $k = 0; for ($i = 0; $i < count($a); $i++) { $k++; if ($a[$i] === 1) { $a[] = 2; } } return $k; }',
            '/** @param list<int> $a */ function f(array $a) { $k = 0; for ($i = 0, $n = count($a); $i < $n; $i++) { $k++; if ($a[$i] === 1) { $a[] = 2; } } return $k; }',
            'App\f',
        ];
        yield 'count() hoisted for a Countable with a side effect' => [
            'final class Shrinking implements \Countable { private int $n = 3; public function count(): int { return $this->n--; } } function f(Shrinking $c) { $k = 0; for ($i = 0; $i < count($c); $i++) { $k++; } return $k; }',
            'final class Shrinking implements \Countable { private int $n = 3; public function count(): int { return $this->n--; } } function f(Shrinking $c) { $k = 0; for ($i = 0, $n = count($c); $i < $n; $i++) { $k++; } return $k; }',
            'App\f',
        ];
        if (\version_compare(TestPhp::minor(), '8.4', '>=')) {
            yield 'property extracted although its get hook has a side effect' => [
                'final class Hooked { private int $reads = 0; public int $value { get => ++$this->reads; } public function twice() { return $this->value + $this->value; } }',
                'final class Hooked { private int $reads = 0; public int $value { get => ++$this->reads; } public function twice() { $value = $this->value; return $value + $value; } }',
                'App\Hooked::twice',
            ];
        }
    }

    /**
     * [before, after, function key]: the change must be accepted.
     *
     * @return iterable<string, array{string, string, non-empty-string}>
     */
    public static function equivalents(): iterable
    {
        yield 'temporary variable inlined' => [
            'function f(int $a, int $b) { $sum = $a + $b; return $sum; }',
            'function f(int $a, int $b) { return $a + $b; }',
            'App\f',
        ];
        yield 'fully qualified call of a function that is not faked' => [
            'function f(string $s) { return strlen($s) + 1; }',
            'function f(string $s) { return \strlen($s) + 1; }',
            'App\f',
        ];
        yield 'nested if becomes early return' => [
            'function f(?array $a) { if ($a !== null) { if (count($a) > 2) { return "many"; } else { return "few"; } } return "none"; }',
            'function f(?array $a) { if ($a === null) { return "none"; } if (count($a) > 2) { return "many"; } return "few"; }',
            'App\f',
        ];
        yield 'isset ternary becomes ??' => [
            'function f(array $a) { return isset($a["k"]) ? $a["k"] : "default"; }',
            'function f(array $a) { return $a["k"] ?? "default"; }',
            'App\f',
        ];
        yield 'count() === 0 becomes === []' => [
            'function f(array $a) { if (count($a) === 0) { return true; } return false; }',
            'function f(array $a) { return $a === []; }',
            'App\f',
        ];
        yield 'in_array with a literal list becomes comparisons' => [
            'function f(string $mode) { return in_array($mode, ["read", "write"], true) ? 1 : 0; }',
            'function f(string $mode) { return $mode === "read" || $mode === "write" ? 1 : 0; }',
            'App\f',
        ];
        yield 'strpos !== false becomes str_contains' => [
            'function f(string $haystack) { return strpos($haystack, "@") !== false; }',
            'function f(string $haystack) { return str_contains($haystack, "@"); }',
            'App\f',
        ];
        yield 'array_push becomes []=' => [
            'function f(array $a, $x) { array_push($a, $x); return $a; }',
            'function f(array $a, $x) { $a[] = $x; return $a; }',
            'App\f',
        ];
        yield 'concatenation becomes interpolation' => [
            'function f(string $name, int $n) { return "Hello, " . $name . " (" . $n . ")"; }',
            'function f(string $name, int $n) { return "Hello, {$name} ({$n})"; }',
            'App\f',
        ];
        yield 'post-increment becomes pre-increment' => [
            'function f(array $xs) { $n = 0; foreach ($xs as $x) { if ($x) { $n++; } } return $n; }',
            'function f(array $xs) { $n = 0; foreach ($xs as $x) { if ($x) { ++$n; } } return $n; }',
            'App\f',
        ];
        yield 'readonly property extracted across method calls' => [
            'final class Mailer { public array $sent = []; public function send(string $m): void { $this->sent[] = $m; } } final class Service { public function __construct(private readonly Mailer $mailer = new Mailer()) {} public function run(string $a) { $this->mailer->send($a); $this->mailer->send(strtoupper($a)); return $this->mailer->sent; } }',
            'final class Mailer { public array $sent = []; public function send(string $m): void { $this->sent[] = $m; } } final class Service { public function __construct(private readonly Mailer $mailer = new Mailer()) {} public function run(string $a) { $mailer = $this->mailer; $mailer->send($a); $mailer->send(strtoupper($a)); return $mailer->sent; } }',
            'App\Service::run',
        ];
        yield 'element of a proven shape extracted' => [
            'function address(string $host, int $port) { $c = ["host" => $host, "port" => $port]; return $c["host"] . ":" . $c["port"] . " (" . $c["host"] . ")"; }',
            'function address(string $host, int $port) { $c = ["host" => $host, "port" => $port]; $h = $c["host"]; return $h . ":" . $c["port"] . " (" . $h . ")"; }',
            'App\address',
        ];
        yield 'count() of an invariant array hoisted out of the loop' => [
            '/** @param list<int> $a */ function sum(array $a) { $s = 0; for ($i = 0; $i < \count($a); $i++) { $s += $a[$i]; } return $s; }',
            '/** @param list<int> $a */ function sum(array $a) { $s = 0; for ($i = 0, $n = \count($a); $i < $n; $i++) { $s += $a[$i]; } return $s; }',
            'App\sum',
        ];
        yield 'global functions and constants qualified' => [
            'function line(string $s) { return strlen(trim($s)) . PHP_EOL . (is_numeric($s) ? M_PI : 0); }',
            'function line(string $s) { return \strlen(\trim($s)) . \PHP_EOL . (\is_numeric($s) ? \M_PI : 0); }',
            'App\line',
        ];
        yield 'repeated property fetch extracted' => [
            'final class User { public function __construct(public readonly array $roles = []) {} public function f() { return count($this->roles) > 0 && in_array("admin", $this->roles, true); } }',
            'final class User { public function __construct(public readonly array $roles = []) {} public function f() { $roles = $this->roles; return count($roles) > 0 && in_array("admin", $roles, true); } }',
            'App\User::f',
        ];
        yield 'mock calls kept in order with a temporary' => [
            self::LOG . ' function f(Log $log, string $m) { $log->write("start"); $log->write($m); return strlen($m); }',
            self::LOG . ' function f(Log $log, string $m) { $log->write("start"); $log->write($m); $len = strlen($m); return $len; }',
            'App\f',
        ];
        yield 'intval becomes a cast' => [
            'function f($x) { return is_scalar($x) ? intval($x) : 0; }',
            'function f($x) { return is_scalar($x) ? (int) $x : 0; }',
            'App\f',
        ];
        yield 'static counter rewritten' => [
            'function f() { static $n = 0; $n = $n + 1; return $n; }',
            'function f() { static $n = 0; return ++$n; }',
            'App\f',
        ];
        yield 'loop without braces gets them' => [
            'function f(array $xs) { foreach ($xs as $x) if ($x > 0) return $x; return 0; }',
            'function f(array $xs) { foreach ($xs as $x) { if ($x > 0) { return $x; } } return 0; }',
            'App\f',
        ];
        yield 'closure body simplified' => [
            'function make(int $k) { return function (int $x) use ($k) { $r = $x * $k; return $r; }; }',
            'function make(int $k) { return function (int $x) use ($k) { return $x * $k; }; }',
            'App\make::{closure:1}',
        ];
    }

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-traps-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0777, true);
    }

    #[AfterTest]
    public function removeProject(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    /**
     * @param non-empty-string $key
     */
    #[DataProvider('traps')]
    public function trapIsRejected(string $before, string $after, string $key): void
    {
        $verdict = $this->verify($before, $after, $key);

        Assert::same($verdict->status, VerdictStatus::Mismatch, "{$verdict->status->value}: {$verdict->reason}");
        Assert::notNull($verdict->counterexample);
    }

    /**
     * @param non-empty-string $key
     */
    #[DataProvider('equivalents')]
    public function equivalentPairIsAccepted(string $before, string $after, string $key): void
    {
        $verdict = $this->verify($before, $after, $key);

        Assert::same($verdict->status, VerdictStatus::Equivalent, $verdict->reason);
    }

    /**
     * @param non-empty-string $key
     */
    private function verify(string $before, string $after, string $key): Verdict
    {
        $original = "<?php\nnamespace App;\n{$before}\n";
        $changed = "<?php\nnamespace App;\n{$after}\n";
        $file = Path::create($this->dir)->join('Code.php');
        \file_put_contents((string) $file, $original);
        $config = new Verification();
        $config->fuzzTimeMs = 1000;

        return (new DiffTester(TestPhp::binary(), $config, Path::create($this->dir)))
            ->verify(new DiffTask($key, $file, 'Code.php', $original, $changed));
    }
}
