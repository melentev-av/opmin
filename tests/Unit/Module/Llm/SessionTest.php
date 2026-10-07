<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Llm;

use Internal\Path;
use Opmin\Module\Llm\Attempt;
use Opmin\Module\Llm\Session;
use Opmin\Module\Llm\Target;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Session::class)]
#[Covers(Attempt::class)]
#[Covers(Target::class)]
final class SessionTest
{
    private string $dir = '';

    #[BeforeTest]
    public function createRuns(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-session-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/runs', 0777, true);
    }

    #[AfterTest]
    public function removeRuns(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function theLatestSessionKeepsItsTargetsAndAttempts(): void
    {
        $runs = Path::create($this->dir . '/runs');
        Session::start($runs->join('20260101-000000'), [new Target('App\Old::f', 'src/Old.php', 3, 9)], '8.4.1');
        $started = Session::start($runs->join('20260102-000000'), [new Target('App\Svc::f', 'src/Svc.php', 7, 12)], '8.4.1');
        $number = $started->nextNumber();
        $candidate = $started->saveCandidate($number, 'public function f() {}');
        $started->record(new Attempt($number, 'App\Svc::f', 'src/Svc.php', false, 0, 'opcodes grew by 1', $candidate));
        $before = $started->saveBefore(2, '<?php');
        $started->record(new Attempt(2, 'App\Svc::f', 'src/Svc.php', true, 3, 'accepted', 'candidates/002.php', 'diff-tested', 'abc', $before));

        $session = Session::open($runs);

        Assert::same((string) $session->runDir, (string) $runs->join('20260102-000000'));
        Assert::same($session->target('\app\svc::F')?->line, 7);
        Assert::same($session->target('App\Old::f'), null);
        Assert::same([$number, $candidate, $before], [1, 'candidates/001.php', 'steps/002.before']);
        Assert::same(\file_get_contents((string) $session->runDir->join($candidate)), 'public function f() {}');
        Assert::same(\array_map(static fn(Attempt $a): array => $a->toArray(), $session->attempts('App\Svc::f')), [
            ['n' => 1, 'function' => 'App\Svc::f', 'file' => 'src/Svc.php', 'accepted' => false, 'gain' => 0, 'reason' => 'opcodes grew by 1', 'candidate' => 'candidates/001.php'],
            ['n' => 2, 'function' => 'App\Svc::f', 'file' => 'src/Svc.php', 'accepted' => true, 'gain' => 3, 'reason' => 'accepted', 'status' => 'diff-tested', 'commit' => 'abc', 'candidate' => 'candidates/002.php', 'before' => 'steps/002.before'],
        ]);
        Assert::same($session->attempts('App\Other::g'), []);
        Assert::same($session->nextNumber(), 3);
        Assert::false($session->finished);
        $session->markFinished();
        Assert::true(Session::open($runs, $session->runDir)->finished);
        Assert::same(Session::open($runs, $runs->join('20260101-000000'))->targets[0]->key, 'App\Old::f');
    }

    public function withoutASessionThereIsNothingToOpen(): never
    {
        Expect::exception(\RuntimeException::class)->withMessage('No LLM session: start one with `opmin llm:targets`.');

        Session::open(Path::create($this->dir . '/runs'));
    }
}
