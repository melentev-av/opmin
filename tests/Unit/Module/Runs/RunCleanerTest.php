<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Runs;

use Internal\Path;
use Opmin\Module\Runs\CleanPlan;
use Opmin\Module\Runs\Run;
use Opmin\Module\Runs\RunCleaner;
use Opmin\Module\Runs\RunIndex;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(RunCleaner::class)]
#[Covers(CleanPlan::class)]
final class RunCleanerTest
{
    private string $dir = '';

    #[BeforeTest]
    public function createRuns(): void
    {
        $this->dir = RunFixtures::tempDir('opmin-clean');
        $runs = $this->dir . '/.opmin/runs';
        RunFixtures::write($runs, '20261001-100000', RunFixtures::FINISHED_OPTIMIZE);
        RunFixtures::write($runs, '20261002-100000', RunFixtures::INTERRUPTED_OPTIMIZE);
        RunFixtures::write($runs, '20261003-100000', RunFixtures::OPEN_LLM);
        RunFixtures::write($runs, '20261004-100000', RunFixtures::FINISHED_LLM);
        RunFixtures::write($runs, '20261005-100000', RunFixtures::BACKUP);
        RunFixtures::write($runs, '20261006-100000', RunFixtures::VERIFY);
        RunFixtures::write($runs, '20261007-100000', RunFixtures::BROKEN);
        RunFixtures::write($runs, '20261008-100000', RunFixtures::FINISHED_OPTIMIZE);
        RunFixtures::write($runs, '20261008-100000-2', RunFixtures::PACKAGE);
        RunFixtures::write($runs, 'notes', ['todo.txt' => 'mine']);
        foreach (['20261001-100000', '20261004-100000', '20261008-100000'] as $id) {
            \file_put_contents("{$this->dir}/.opmin/report_{$id}.md", "# copy\n");
        }
    }

    #[AfterTest]
    public function removeRuns(): void
    {
        RunFixtures::remove($this->dir);
    }

    public function keepsTheNewestFinishedAndEveryRunThatCanContinue(): void
    {
        $plan = RunCleaner::plan($this->runs(false), 2, false);

        # Kept: the package copy and 20261008-100000 (the two newest finished), the interrupted run, the open
        # session, the backups, `notes`. The broken run has an original/ too: outside git it is a backup.
        Assert::same(self::ids($plan->delete), ['20261006-100000', '20261004-100000', '20261001-100000']);
        Assert::same(self::ids($plan->backups), ['20261007-100000', '20261005-100000']);
    }

    public function backupsAreDeletedOnlyWhenAsked(): void
    {
        $plan = RunCleaner::plan($this->runs(false), 0, true);

        Assert::same(
            self::ids($plan->delete),
            ['20261008-100000-2', '20261008-100000', '20261007-100000', '20261006-100000', '20261005-100000', '20261004-100000', '20261001-100000'],
        );
        Assert::same($plan->backups, []);
    }

    public function underGitNothingIsABackup(): void
    {
        $plan = RunCleaner::plan($this->runs(true), 0, false);

        Assert::same(self::ids($plan->backups), []);
        Assert::true(\in_array('20261005-100000', self::ids($plan->delete), true));
        Assert::true(\in_array('20261007-100000', self::ids($plan->delete), true));
    }

    public function aBrokenRunMayBeOneStillStarting(): void
    {
        $dir = RunFixtures::write($this->dir . '/.opmin/runs', '20261009-100000', [], \time() - 60);
        $runs = $this->runs(true);

        Assert::false(\in_array('20261009-100000', self::ids(RunCleaner::plan($runs, 0, true)->delete), true));
        Assert::true(\in_array('20261009-100000', self::ids(RunCleaner::plan($runs, 0, true, null, \time() + 3600)->delete), true));
        Assert::false(\in_array('20261008-100000', self::ids(RunCleaner::plan($runs, 0, true, Path::create($this->dir . '/.opmin/runs/20261008-100000'))->delete), true));
        Assert::string($dir)->contains('20261009-100000');
    }

    public function deletesTheRunsWithTheirReportCopies(): void
    {
        $plan = RunCleaner::plan($this->runs(false), 2, false);

        $errors = RunCleaner::apply($plan);

        Assert::same($errors, []);
        Assert::same(self::ids($this->runs(false)), [
            '20261008-100000-2', '20261008-100000', '20261007-100000', '20261005-100000', '20261003-100000', '20261002-100000', 'notes',
        ]);
        Assert::false(\is_file("{$this->dir}/.opmin/report_20261001-100000.md"));
        Assert::false(\is_file("{$this->dir}/.opmin/report_20261004-100000.md"));
        Assert::true(\is_file("{$this->dir}/.opmin/report_20261008-100000.md"));
    }

    public function pruneIsOffByDefaultAndNeverThrows(): void
    {
        $runsDir = Path::create($this->dir . '/.opmin/runs');
        $current = $runsDir->join('20261008-100000');

        Assert::same(RunCleaner::prune(null, $runsDir, $current, true), [[], []]);
        Assert::string(RunCleaner::prune(-1, $runsDir, $current, true)[1][0] ?? '')->contains('runs.keep must be 0 or more');

        [$deleted, $warnings] = RunCleaner::prune(0, $runsDir, $current, true);

        Assert::same($warnings, []);
        Assert::same(self::ids($deleted), ['20261008-100000-2', '20261007-100000', '20261006-100000', '20261005-100000', '20261004-100000', '20261001-100000']);
        Assert::same(self::ids($this->runs(true)), ['20261008-100000', '20261003-100000', '20261002-100000', 'notes']);
    }

    /**
     * @param list<Run> $runs
     * @return list<string>
     */
    private static function ids(array $runs): array
    {
        return \array_map(static fn(Run $r): string => $r->id, $runs);
    }

    /**
     * @return list<Run>
     */
    private function runs(bool $underGit): array
    {
        return RunIndex::scan(Path::create($this->dir . '/.opmin/runs'), $underGit);
    }
}
