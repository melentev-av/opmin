<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Command;

use Opmin\Command\Runs;
use Opmin\Command\RunsClean;
use Opmin\Command\RunsShow;
use Opmin\Command\Stage;
use Opmin\Tests\Integration\TestPhp;
use Opmin\Tests\Unit\Module\Runs\RunFixtures;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * `opmin runs`, `runs:show`, `runs:clean` and `runs.keep` as processes on a project (not under git) with
 * run directories of every kind.
 */
#[Test]
#[Covers(Runs::class)]
#[Covers(RunsShow::class)]
#[Covers(RunsClean::class)]
#[Covers(Stage::class)]
final class RunsTest
{
    private string $dir = '';

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = RunFixtures::tempDir('opmin-runs-cli');
        \mkdir($this->dir . '/src');
        \file_put_contents($this->dir . '/composer.json', '{"require": {"php": ">=8.1"}}');
        \file_put_contents($this->dir . '/src/Text.php', "<?php\n\nfunction slug(string \$s): string\n{\n    return strtolower(trim(\$s));\n}\n");
        \file_put_contents($this->dir . '/opmin.yaml', "commands:\n  format: none\n");
        $runs = $this->dir . '/.opmin/runs';
        RunFixtures::write($runs, '20261001-100000', RunFixtures::FINISHED_OPTIMIZE);
        \file_put_contents($this->dir . '/.opmin/report_20261001-100000.md', "# optimize\n");
        RunFixtures::write($runs, '20261002-100000', RunFixtures::INTERRUPTED_OPTIMIZE);
        RunFixtures::write($runs, '20261003-100000', RunFixtures::OPEN_LLM);
        RunFixtures::write($runs, '20261004-100000', RunFixtures::FINISHED_LLM);
        RunFixtures::write($runs, '20261005-100000', RunFixtures::BACKUP);
        RunFixtures::write($runs, '20261006-100000', RunFixtures::VERIFY);
    }

    #[AfterTest]
    public function removeProject(): void
    {
        RunFixtures::remove($this->dir);
    }

    public function listsEveryRunWithFixedFields(): void
    {
        [$code, $out, $err] = $this->opmin('runs', '--format=json');

        Assert::same($code, 0, $err);
        /** @var array{runs: list<array<string, mixed>>} $data */
        $data = \json_decode($out, true, flags: \JSON_THROW_ON_ERROR);
        Assert::same(\array_keys($data['runs'][0]), ['id', 'kind', 'state', 'ops_before', 'ops_after', 'size', 'report', 'backup', 'path']);
        Assert::same(
            \array_map(static fn(array $r): string => "{$r['id']} {$r['kind']} {$r['state']}", $data['runs']),
            [
                '20261006-100000 verify finished',
                '20261005-100000 optimize finished',
                '20261004-100000 llm finished',
                '20261003-100000 llm open',
                '20261002-100000 optimize interrupted',
                '20261001-100000 optimize finished',
            ],
        );
        Assert::same($data['runs'][5]['ops_before'], 442);
        Assert::same($data['runs'][1]['backup'], true);
        Assert::same($data['runs'][5]['path'], '.opmin/runs/20261001-100000');

        [$code, $table] = $this->opmin('runs');

        Assert::same($code, 0);
        Assert::string($table)->contains('442 → 372')->contains('interrupted')->contains('finished (backup)')->contains('6 run(s)');
        Assert::same($this->opmin('runs', '--format=xml')[0], 2);
    }

    public function showsTheNewestReport(): void
    {
        Assert::same($this->opmin('runs:show'), [0, "# backup\n", '']);
        Assert::same($this->opmin('runs:show', '20261001-100000', '--format=json')[1], RunFixtures::FINISHED_OPTIMIZE['report.json']);
        Assert::same($this->opmin('runs:show', '.opmin/runs/20261001-100000/')[1], "# optimize\n");
        Assert::same($this->opmin('runs:show', '--format=path')[1], $this->dir . "/.opmin/runs/20261005-100000/report.md\n");

        [$code, , $err] = $this->opmin('runs:show', '20261002-100000');
        Assert::same($code, 2);
        Assert::string($err)->contains('has no report');

        [$code, , $err] = $this->opmin('runs:show', '20990101-000000');
        Assert::same($code, 2);
        Assert::string($err)->contains('No run 20990101-000000');
        Assert::same($this->opmin('runs:show', '--format=html')[0], 2);
    }

    public function noReportAtAllIsExitTwo(): void
    {
        RunFixtures::remove($this->dir . '/.opmin/runs');

        [$code, , $err] = $this->opmin('runs:show');

        Assert::same($code, 2);
        Assert::string($err)->contains('No run with a report');
    }

    public function cleanKeepsWhatCanContinueAndTheBackups(): void
    {
        [$code, $plan] = $this->opmin('runs:clean', '--keep=1', '--dry-run');

        Assert::same($code, 0);
        Assert::string($plan)
            ->contains('Would delete .opmin/runs/20261004-100000 (llm, finished')
            ->contains('Would delete .opmin/runs/20261001-100000 (optimize, finished, 159 B) and .opmin/report_20261001-100000.md')
            ->contains('Keeping .opmin/runs/20261005-100000: its original/ is the only copy');
        Assert::true(\is_dir($this->dir . '/.opmin/runs/20261001-100000'));

        [$code, $out] = $this->opmin('runs:clean', '--keep=1');

        Assert::same($code, 0);
        Assert::string($out)->contains('Deleted 2 of 6 run(s)');
        Assert::same($this->ids(), ['20261002-100000', '20261003-100000', '20261005-100000', '20261006-100000']);
        Assert::false(\is_file($this->dir . '/.opmin/report_20261001-100000.md'));

        $this->opmin('runs:clean', '--keep=0', '--include-backups');

        Assert::same($this->ids(), ['20261002-100000', '20261003-100000']);
        Assert::same($this->opmin('runs:clean')[0], 2);
        Assert::same($this->opmin('runs:clean', '--keep=-1')[0], 2);
        Assert::same($this->opmin('runs:clean', '--keep=x')[0], 2);
    }

    public function runsKeepPrunesAfterANewRun(): void
    {
        \file_put_contents($this->dir . '/opmin.yaml', "commands:\n  format: none\nruns:\n  keep: 1\n");

        [$code, $out, $err] = $this->opmin('llm:targets', '--format=json');

        Assert::same($code, 0, $out . $err);
        Assert::string($err)->contains('runs.keep: 1: deleted 2 old run(s): 20261004-100000, 20261001-100000');
        /** @var array{run: string} $data */
        $data = \json_decode($out, true, flags: \JSON_THROW_ON_ERROR);
        # The new session is open; of the finished runs only the newest stays, the backup stays too.
        Assert::same($this->ids(), ['20261002-100000', '20261003-100000', '20261005-100000', '20261006-100000', \basename($data['run'])]);
    }

    /**
     * @return list<string>
     */
    private function ids(): array
    {
        $ids = \array_values(\array_diff((array) \scandir($this->dir . '/.opmin/runs'), ['.', '..']));
        \sort($ids);

        return \array_map('strval', $ids);
    }

    /**
     * @return array{int, string, string}
     */
    private function opmin(string ...$args): array
    {
        $process = \proc_open(
            [\PHP_BINARY, __DIR__ . '/../../../bin/opmin', '--no-ansi', '--no-interaction', ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            \array_merge(\getenv(), ['OPMIN_PHP_BINARY' => TestPhp::path()]),
        );
        \fclose($pipes[0]);
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);

        return [\proc_close($process), $out, $err];
    }
}
