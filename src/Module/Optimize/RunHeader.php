<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize;

use Internal\Path;
use Opmin\Module\Config\Schema;
use Opmin\Module\Lint\PhpStanRunner;
use Opmin\Module\Opcode\OpcodeCounter;
use Opmin\Module\Optimize\Rector\RuleSpec;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Project\Project;
use Opmin\Module\Tests\CommandAdapter;
use Opmin\Module\Tests\PhpUnitAdapter;
use Opmin\Module\Tests\TestRunnerFactory;
use Opmin\Module\Verification\DiffPool;

/**
 * What an `optimize` run will use, said before it starts (`--plan` says only this): the functions,
 * the rules, the test runner and whether it can pick the tests of a changed function, PHPStan, the
 * formatter, the differential tests at a time. Everything here is cheap: the counts come from the
 * cache, the coverage driver is one `php -r`; nothing runs the project's tests.
 *
 * No time estimate: there is nothing to base one on but the timings of a previous run.
 *
 * @internal
 */
final readonly class RunHeader
{
    /** Rule names listed; the rest is a count. */
    private const RULES_SHOWN = 8;

    /**
     * @param non-empty-string $title The first line: the files, the project, PHP, the workspace.
     * @param int<0, max> $functions Functions counted in the target files.
     * @param int<0, max> $optimizable Of them, not main code.
     * @param list<non-empty-string> $rules Short names of the rules.
     * @param non-empty-string|null $runner Name of the project's test runner; null without one.
     * @param bool $selective Whether the runner can run only the tests of a changed function (not `tests.command`).
     * @param bool $coverageDriver Whether `php.binary` has pcov or Xdebug.
     * @param non-empty-string $phpstan The command, or why there is none.
     * @param non-empty-string $formatter {@see Formatter::describe()}.
     * @param positive-int $jobs Differential tests at a time, resolved.
     * @param int<0, max> $configuredJobs `verification.jobs`: 0 — auto.
     */
    public function __construct(
        public string $title,
        public int $functions,
        public int $optimizable,
        public array $rules,
        public ?string $runner,
        public bool $selective,
        public bool $coverageDriver,
        public string $phpstan,
        public string $formatter,
        public int $jobs,
        public int $configuredJobs,
    ) {}

    /**
     * @param non-empty-string $title
     * @param list<Path> $files
     * @param list<RuleSpec> $rules
     * @param non-empty-string|null $phpTarget
     * @param Path $workDir Where a test runner would put its reports; nothing is written there.
     * @throws \InvalidArgumentException `tests.runner: command` without `tests.command`.
     */
    public static function detect(
        string $title,
        Project $project,
        PhpBinary $php,
        array $files,
        array $rules,
        OpcodeCounter $counter,
        Schema\Tests $tests,
        Schema\Commands $commands,
        Schema\Verification $verification,
        ?string $phpTarget,
        Path $workDir,
    ): self {
        $counts = $files === [] ? [] : $counter->count($project, $files)->functions;
        $optimizable = 0;
        foreach ($counts as $count) {
            $count->optimizable and ++$optimizable;
        }

        $runner = TestRunnerFactory::create($project, $tests, $php, $workDir);
        $phpstan = match (true) {
            $commands->phpstan === null => 'none (commands.phpstan is null)',
            (new PhpStanRunner($project, $php, $commands->phpstan, $phpTarget, $workDir))->available() => $commands->phpstan,
            default => 'none (not installed in the project)',
        };
        $formatter = Formatter::create($project, $php, $commands->format)->describe();

        return new self(
            $title,
            \count($counts),
            $optimizable,
            \array_map(static fn(RuleSpec $rule): string => $rule->shortName(), $rules),
            $runner?->name(),
            $runner !== null && !$runner instanceof CommandAdapter,
            PhpUnitAdapter::hasCoverageDriver($php),
            $phpstan,
            $formatter === '' ? 'no formatter' : $formatter,
            DiffPool::jobs($verification->jobs, $verification->memoryLimit),
            \max(0, $verification->jobs),
        );
    }

    /**
     * The header, the title first.
     *
     * @return non-empty-list<non-empty-string>
     */
    public function lines(): array
    {
        $rules = \array_slice($this->rules, 0, self::RULES_SHOWN);
        $more = \count($this->rules) - \count($rules);

        return [
            $this->title,
            \sprintf('  Functions: %d, %d of them optimizable', $this->functions, $this->optimizable),
            '  Rules: ' . ($rules === [] ? 'none' : \implode(', ', $rules) . ($more > 0 ? ", … {$more} more" : '')),
            '  Tests: ' . match (true) {
                $this->runner === null => 'none: behavior is checked by the differential tests only',
                !$this->selective => "{$this->runner} (tests.command): the whole suite on every step",
                $this->coverageDriver => "{$this->runner}, the tests that cover a changed function",
                default => "{$this->runner}, the whole suite on every step",
            },
            '  Coverage driver: ' . ($this->coverageDriver ? 'yes (pcov or Xdebug in php.binary)' : 'no (neither pcov nor Xdebug in php.binary)'),
            '  PHPStan: ' . $this->phpstan,
            '  Formatter: ' . $this->formatter,
            \sprintf(
                '  Differential tests: %d at a time (verification.jobs: %s)',
                $this->jobs,
                $this->configuredJobs === 0 ? '0, auto' : (string) $this->configuredJobs,
            ),
        ];
    }

    /**
     * Why every step will run the whole test suite of the project; null when it will not.
     *
     * @return non-empty-list<non-empty-string>|null
     */
    public function warning(): ?array
    {
        return match (true) {
            $this->runner === null => null,
            !$this->selective => [
                'Every step runs the whole test suite: tests.command is a plain command, opmin cannot pick the tests that cover a changed function.',
                'Set tests.runner to phpunit, pest or testo, and install pcov for php.binary, to run only those tests.',
            ],
            !$this->coverageDriver => [
                'Every step runs the whole test suite: php.binary has neither pcov nor Xdebug, so there is no coverage map to pick the tests that cover a changed function.',
                'Install pcov for php.binary (pecl install pcov): it is the fastest coverage driver.',
            ],
            default => null,
        };
    }
}
