<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Runs;

use Internal\Path;
use Opmin\Module\Runs\Run;
use Opmin\Module\Runs\RunIndex;
use Opmin\Module\Runs\RunKind;
use Opmin\Module\Runs\RunStatus;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(RunIndex::class)]
#[Covers(Run::class)]
final class RunIndexTest
{
    private string $dir = '';

    public static function directories(): iterable
    {
        yield 'finished optimize' => [RunFixtures::FINISHED_OPTIMIZE, false, RunKind::Optimize, RunStatus::Finished, 442, 372, true, false];
        yield 'interrupted optimize' => [RunFixtures::INTERRUPTED_OPTIMIZE, true, RunKind::Optimize, RunStatus::Interrupted, null, null, false, false];
        yield 'optimize closing' => [RunFixtures::CLOSING_OPTIMIZE, false, RunKind::Optimize, RunStatus::Interrupted, null, null, false, false];
        yield 'unreadable state' => [['state.json' => '{'], false, RunKind::Optimize, RunStatus::Interrupted, null, null, false, false];
        yield 'open LLM session' => [RunFixtures::OPEN_LLM, false, RunKind::Llm, RunStatus::Open, null, null, false, false];
        yield 'finished LLM session' => [RunFixtures::FINISHED_LLM, false, RunKind::Llm, RunStatus::Finished, 100, 90, true, false];
        yield 'verify' => [RunFixtures::VERIFY, false, RunKind::Verify, RunStatus::Finished, null, null, false, false];
        yield 'package copy' => [RunFixtures::PACKAGE, false, RunKind::Package, RunStatus::Finished, 5, 4, true, false];
        yield 'broken (git failed)' => [RunFixtures::BROKEN, true, RunKind::Broken, RunStatus::Broken, null, null, false, false];
        yield 'empty' => [[], false, RunKind::Broken, RunStatus::Broken, null, null, false, false];
        yield 'report without state' => [['report.json' => '{"kind": "llm"}'], false, RunKind::Llm, RunStatus::Finished, null, null, true, false];
        yield 'backup outside git' => [RunFixtures::BACKUP, false, RunKind::Optimize, RunStatus::Finished, 9, 8, true, true];
        yield 'no backup under git' => [RunFixtures::BACKUP, true, RunKind::Optimize, RunStatus::Finished, 9, 8, true, false];
        yield 'no backup in a dry run' => [RunFixtures::DRY_RUN, false, RunKind::Optimize, RunStatus::Finished, 9, 8, true, false];
    }

    #[BeforeTest]
    public function createRuns(): void
    {
        $this->dir = RunFixtures::tempDir('opmin-runs');
    }

    #[AfterTest]
    public function removeRuns(): void
    {
        RunFixtures::remove($this->dir);
    }

    /**
     * @param array<non-empty-string, string> $files
     */
    #[DataProvider('directories')]
    public function classifiesADirectory(
        array $files,
        bool $underGit,
        RunKind $kind,
        RunStatus $status,
        ?int $before,
        ?int $after,
        bool $report,
        bool $backup,
    ): void {
        RunFixtures::write($this->runsDir(), '20261011-100000', $files);

        $runs = RunIndex::scan(Path::create($this->runsDir()), $underGit);

        Assert::count($runs, 1);
        Assert::same($runs[0]->kind, $kind);
        Assert::same($runs[0]->status, $status);
        Assert::same([$runs[0]->opsBefore, $runs[0]->opsAfter], [$before, $after]);
        Assert::same($runs[0]->report, $report);
        Assert::same($runs[0]->backup, $backup);
        Assert::same($runs[0]->size, \array_sum(\array_map(\strlen(...), $files)));
    }

    public function newestFirstWithNumericSuffixes(): void
    {
        foreach (['20261011-100000-10', '20261011-100000', 'notes', '20261011-100000-2', '20261012-090000', '20261010-235959'] as $id) {
            RunFixtures::write($this->runsDir(), $id, []);
        }

        $runs = RunIndex::scan(Path::create($this->runsDir()), true);

        Assert::same(
            \array_map(static fn(Run $r): string => $r->id, $runs),
            ['20261012-090000', '20261011-100000-10', '20261011-100000-2', '20261011-100000', '20261010-235959', 'notes'],
        );
        Assert::same(\array_map(static fn(Run $r): bool => $r->named, $runs), [true, true, true, true, true, false]);
    }

    public function findsTheNewestRunWithAReport(): void
    {
        RunFixtures::write($this->runsDir(), '20261011-100000', RunFixtures::FINISHED_OPTIMIZE);
        RunFixtures::write($this->runsDir(), '20261011-100000-2', RunFixtures::FINISHED_LLM);
        RunFixtures::write($this->runsDir(), '20261011-110000', RunFixtures::INTERRUPTED_OPTIMIZE);
        RunFixtures::write($this->runsDir(), '20261011-120000', RunFixtures::VERIFY);
        $runs = RunIndex::scan(Path::create($this->runsDir()), true);

        Assert::same(RunIndex::find($runs, null)?->id, '20261011-100000-2');
        Assert::same(RunIndex::find($runs, '20261011-100000')?->id, '20261011-100000');
        Assert::same(RunIndex::find($runs, '20261011-110000')?->report, false);
        Assert::null(RunIndex::find($runs, '20261011-130000'));
        Assert::null(RunIndex::find([], null));
    }

    public function noRunsDirectoryIsNoRuns(): void
    {
        Assert::same(RunIndex::scan(Path::create($this->dir . '/missing'), true), []);
    }

    private function runsDir(): string
    {
        return $this->dir . '/.opmin/runs';
    }
}
