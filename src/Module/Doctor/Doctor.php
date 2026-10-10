<?php

declare(strict_types=1);

namespace Opmin\Module\Doctor;

use Internal\Path;
use Opmin\Module\Common\Cache\MemoryStore;
use Opmin\Module\Common\Cache\SqliteStore;
use Opmin\Module\Common\Cache\StoreFactory;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Config\Exception\ConfigException;
use Opmin\Module\Config\Schema;
use Opmin\Module\Harness\Worker;
use Opmin\Module\Lint\PhpStanRunner;
use Opmin\Module\Opcode\CountCache;
use Opmin\Module\Opcode\Dump\OpcacheDumper;
use Opmin\Module\Opcode\OpcodeCounter;
use Opmin\Module\Optimize\Formatter;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Php\PhpBinaryException;
use Opmin\Module\Php\PhpBinaryProbe;
use Opmin\Module\Project\FileFinder;
use Opmin\Module\Project\Project;
use Opmin\Module\Project\Targets;
use Opmin\Module\Release\Installation;
use Opmin\Module\Tests\CommandLine;
use Opmin\Module\Tests\PhpUnitAdapter;
use Opmin\Module\Tests\TestRunnerFactory;
use Symfony\Component\Process\Process;

/**
 * The checks of `opmin doctor`, in the order a user fixes them: `php.binary` first (nothing works without
 * it), then OPcache and the harness under it, then what the project brings — PHP version, syntax, tests,
 * PHPStan, coverage, git.
 *
 * @internal
 */
final readonly class Doctor
{
    /** How many unparsable files are named in the message. */
    private const SYNTAX_SHOWN = 5;

    /**
     * Runs under `php.binary`: reads a JSON list of files from stdin, prints the ones it cannot parse.
     */
    private const SYNTAX_SCRIPT = <<<'PHP'
        $errors = [];
        foreach (json_decode(stream_get_contents(STDIN), true) as $file) {
            try {
                token_get_all((string) file_get_contents($file), TOKEN_PARSE);
            } catch (\ParseError $e) {
                $errors[] = ['file' => $file, 'line' => $e->getLine(), 'message' => $e->getMessage()];
            }
        }
        echo json_encode($errors);
        PHP;

    public function __construct(
        private Path $cwd,
        private Schema\Php $php,
        private Schema\Project $project,
        private Schema\Tests $tests,
        private Schema\Commands $commands,
        private Installation $installation,
        private Schema\Cache $cache,
        private Path $cacheDir,
        private StoreFactory $stores,
        private bool $runTests = false,
    ) {}

    /**
     * @return list<Check>
     */
    public function run(): array
    {
        $checks = [$this->binary()];
        try {
            $php = (new PhpBinaryProbe())->probe($this->php->binary);
        } catch (PhpBinaryException $e) {
            $checks[] = $checks[0]->status === Status::Ok
                ? Check::error('OPcache', $e->getMessage(), 'Install the opcache extension for this PHP '
                    . '(e.g. apt install php8.x-opcache, docker-php-ext-install opcache) or set php.binary to a PHP that has it.')
                : Check::skipped('OPcache');

            foreach (['opcache_compile_file', 'harness', 'PHP version', 'syntax', 'coverage', 'tests', 'PHPStan'] as $name) {
                $checks[] = Check::skipped($name);
            }

            return [...$checks, ...$this->tools()];
        }

        $checks[] = Check::ok('OPcache', $php->loadArgs === []
            ? 'loaded in the CLI'
            : 'built but disabled for the CLI: opmin loads it with -d zend_extension=opcache for counting');
        $checks[] = $this->compile($php);
        $checks[] = $this->harness($php);

        try {
            [$project, $paths] = Targets::resolve([], $this->cwd, $this->project);
        } catch (\InvalidArgumentException $e) {
            $project = Project::detect($this->cwd, $this->cwd);
            $paths = [];
            $checks[] = Check::warning('paths', $e->getMessage(), 'Set `paths` in opmin.yaml to the directories with your code (opmin init detects them).');
        }

        $checks[] = $this->version($php, $project);
        $paths === [] or $checks[] = $this->syntax($php, $project, $paths);
        $checks[] = $this->coverage($php);
        $checks[] = $this->testRunner($php, $project);
        $checks[] = $this->phpStan($php, $project);

        return [...$checks, ...$this->tools($project)];
    }

    /**
     * The first lines of an error: a stack trace below them tells the user nothing to fix.
     */
    private static function head(string $text, int $lines = 2): string
    {
        $kept = \array_slice(\array_values(\array_filter(\array_map('trim', \explode("\n", $text)), static fn(string $l): bool => $l !== '')), 0, $lines);

        return $kept === [] ? 'no output' : \implode("\n", $kept);
    }

    private function binary(): Check
    {
        $binary = $this->php->binary;
        $embedded = $this->installation->kind === Installation::BINARY
            ? \sprintf(' opmin itself runs on its embedded PHP %s, but counting, the harness and your tests need the PHP of your production.', \PHP_VERSION)
            : '';
        $process = new Process([$binary, '-d', 'xdebug.mode=off', '-r', 'echo PHP_VERSION, "|", PHP_VERSION_ID, "|", PHP_BINARY;']);
        try {
            $process->run();
        } catch (\Throwable) {
        }

        $parts = \explode('|', \trim($process->getOutput()));
        if (!$process->isSuccessful() || \count($parts) !== 3) {
            return Check::error(
                'php.binary',
                "`{$binary}` cannot be started." . $embedded,
                'Install PHP (8.1 or newer, the version of your production) or set php.binary in opmin.yaml '
                . '(or OPMIN_PHP_BINARY) to its path; the Docker image ghcr.io/melentev-av/opmin has it inside.',
            );
        }

        [$version, $id, $path] = $parts;
        if ((int) $id < PhpBinaryProbe::MIN_VERSION_ID) {
            return Check::error(
                'php.binary',
                "`{$binary}` is PHP {$version}: opmin supports projects on PHP 8.1 and newer." . $embedded,
                'Set php.binary to a PHP 8.1+ binary — the one your production runs.',
            );
        }

        return Check::ok('php.binary', "PHP {$version} ({$path})." . $embedded);
    }

    /**
     * `opcache_compile_file` and the dump really work: a two-line file is counted.
     */
    private function compile(PhpBinary $php): Check
    {
        $dir = FS::tmpDir(sub: 'opmin-doctor-' . \bin2hex(\random_bytes(4)));
        try {
            $file = $dir->join('probe.php');
            \file_put_contents((string) $file, "<?php\nfunction opmin_doctor_probe(int \$x): int { return \$x * 2; }\n");
            $result = (new OpcodeCounter(new OpcacheDumper($php, 1), new CountCache(new MemoryStore(), $php)))
                ->count(new Project($dir, false, null), [$file]);
            foreach ($result->functions as $function) {
                if ($function->key === 'opmin_doctor_probe' && $function->opsOpt > 0) {
                    return Check::ok('opcache_compile_file', 'compiles and dumps opcodes');
                }
            }

            return Check::error(
                'opcache_compile_file',
                'the OPcache dump gave no opcodes: ' . (\implode('; ', $result->errors) ?: 'empty dump'),
                'Check that opcache.opt_debug_level is not disabled in this PHP build and run opmin count -vvv on a file.',
            );
        } catch (\Throwable $e) {
            return Check::error('opcache_compile_file', $e->getMessage(), 'Run opmin count -vvv on a file for the details.');
        } finally {
            FS::remove($dir);
        }
    }

    private function harness(PhpBinary $php): Check
    {
        $worker = new Worker($php);
        try {
            $worker->start();

            return Check::ok('harness', 'the differential-testing worker starts under php.binary');
        } catch (\Throwable $e) {
            return Check::error(
                'harness',
                'the differential-testing worker does not start: ' . $e->getMessage() . ' ' . \trim($worker->stderr()),
                'Make sure the temp directory is writable and visible to php.binary (OPMIN_HARNESS_DIR sets where the harness is extracted).',
            );
        } finally {
            $worker->stop();
        }
    }

    private function version(PhpBinary $php, Project $project): Check
    {
        $target = $this->php->target ?? $project->phpTarget;
        $minor = \implode('.', \array_slice(\explode('.', $php->version), 0, 2));
        if ($target === null) {
            return Check::info('PHP version', "php.target is not set and composer.json has no PHP constraint: code is analyzed for PHP {$minor}.");
        }

        if (\version_compare($target, $minor, '=')) {
            return Check::ok('PHP version', "php.binary {$php->version} matches php.target {$target}");
        }

        return Check::warning(
            'PHP version',
            "php.binary is PHP {$php->version}, php.target is {$target}: opcode counts depend on the PHP version, "
            . 'so they hold only for PHP ' . $minor . '.',
            "Set php.binary to the PHP {$target} your production runs (or the Docker image tag -php{$target}), or fix php.target.",
        );
    }

    /**
     * @param list<Path> $paths
     */
    private function syntax(PhpBinary $php, Project $project, array $paths): Check
    {
        $files = \array_map('strval', (new FileFinder())->find($project, $paths, $this->project->exclude));
        if ($files === []) {
            return Check::warning('syntax', 'no PHP files under `paths`', 'Set `paths` in opmin.yaml to the directories with your code.');
        }

        $process = new Process([$php->path, '-d', 'xdebug.mode=off', '-d', 'display_errors=stderr', '-r', self::SYNTAX_SCRIPT]);
        $process->setInput(\json_encode($files, \JSON_THROW_ON_ERROR));
        $process->setTimeout(600);
        $process->run();
        /** @var mixed $errors */
        $errors = \json_decode($process->getOutput(), true);
        if (!\is_array($errors)) {
            return Check::warning('syntax', 'the syntax check did not finish: ' . \trim($process->getErrorOutput()), 'Run php -l on your files with php.binary.');
        }

        if ($errors === []) {
            return Check::ok('syntax', \sprintf('%d file(s) parse with PHP %s', \count($files), $php->version));
        }

        $shown = [];
        /** @var array{file: string, line: int, message: string} $error */
        foreach (\array_slice($errors, 0, self::SYNTAX_SHOWN) as $error) {
            $shown[] = \sprintf('%s:%d %s', $project->relative(Path::create($error['file'])), $error['line'], $error['message']);
        }

        \count($errors) > self::SYNTAX_SHOWN and $shown[] = \sprintf('… and %d more', \count($errors) - self::SYNTAX_SHOWN);

        return Check::error(
            'syntax',
            \sprintf("%d file(s) do not parse with PHP %s:\n%s", \count($errors), $php->version, \implode("\n", $shown)),
            'php.binary is older than the syntax of your code: set it to the PHP your production runs, or exclude the files (`exclude`).',
        );
    }

    private function coverage(PhpBinary $php): Check
    {
        return PhpUnitAdapter::hasCoverageDriver($php)
            ? Check::ok('coverage', 'pcov or Xdebug is loaded: only the tests that cover a changed function run on each step')
            : Check::warning(
                'coverage',
                'php.binary has neither pcov nor Xdebug: every step runs the whole test suite, and the coverage of the differential tests is not measured',
                'Install pcov for php.binary (pecl install pcov) — it is the fastest coverage driver.',
            );
    }

    private function testRunner(PhpBinary $php, Project $project): Check
    {
        $workDir = FS::tmpDir(sub: 'opmin-doctor-tests-' . \bin2hex(\random_bytes(4)));
        try {
            $runner = TestRunnerFactory::create($project, $this->tests, $php, $workDir);
        } catch (\InvalidArgumentException $e) {
            FS::remove($workDir);
            return Check::error('tests', $e->getMessage(), 'Set tests.command or another tests.runner.');
        }

        try {
            if ($runner === null) {
                return Check::warning(
                    'tests',
                    'no test runner found (PHPUnit, Pest, Testo): behavior is checked by the differential tests only',
                    'Install the project\'s test runner, or set tests.runner: command and tests.command.',
                );
            }

            if ($this->runTests) {
                $started = \microtime(true);
                $result = $runner->runAll();
                $seconds = (int) \round(\microtime(true) - $started);

                return $result->success
                    ? Check::ok('tests', "{$runner->name()}: the suite passes ({$seconds} s)")
                    : Check::error('tests', "{$runner->name()}: the suite fails on the original code ({$seconds} s)", 'opmin accepts no change while the tests fail: fix them first.');
            }

            return $this->runnerStarts($runner->name(), $php, $project);
        } finally {
            FS::remove($workDir);
        }
    }

    /**
     * @param non-empty-string $name
     */
    private function runnerStarts(string $name, PhpBinary $php, Project $project): Check
    {
        if ($name === 'command') {
            return Check::info('tests', 'tests.command: ' . (string) $this->tests->command . ' (run doctor --with-tests to run it)');
        }

        $args = CommandLine::split($this->tests->command ?? "vendor/bin/{$name}");
        $process = new Process([...CommandLine::withPhp($args, $php, $project->root), '--version'], (string) $project->root);
        $process->setTimeout(60);
        try {
            $process->run();
        } catch (\Throwable) {
        }

        return $process->isSuccessful()
            ? Check::ok('tests', \sprintf('%s: %s (run doctor --with-tests to run the suite)', $name, self::head($process->getOutput(), 1)))
            : Check::error(
                'tests',
                "{$name} is detected but does not start: " . self::head($process->getErrorOutput() . $process->getOutput()),
                'Run composer install in the project, or set tests.command to the runner binary.',
            );
    }

    private function phpStan(PhpBinary $php, Project $project): Check
    {
        $command = $this->commands->phpstan;
        if ($command === null) {
            return Check::info('PHPStan', 'commands.phpstan is null: no static check on each step');
        }

        $runner = new PhpStanRunner($project, $php, $command, $this->php->target ?? $project->phpTarget, $project->root);
        if (!$runner->available()) {
            return Check::info('PHPStan', 'not installed in the project: no static check on each step (composer require --dev phpstan/phpstan to add it)');
        }

        $args = CommandLine::split($command);
        $process = new Process([...CommandLine::withPhp([$args[0] ?? 'vendor/bin/phpstan'], $php, $project->root), '--version'], (string) $project->root);
        $process->setTimeout(60);
        $process->run();

        return $process->isSuccessful()
            ? Check::ok('PHPStan', self::head($process->getOutput(), 1))
            : Check::error('PHPStan', 'does not start: ' . self::head($process->getErrorOutput() . $process->getOutput()), 'Run composer install, or set commands.phpstan to null.');
    }

    /**
     * What does not depend on php.binary: git, the formatter, the project's own Rector config.
     *
     * @return list<Check>
     */
    private function tools(?Project $project = null): array
    {
        $checks = [];
        $git = new Process(['git', '--version']);
        try {
            $git->run();
        } catch (\Throwable) {
        }

        if (!$git->isSuccessful()) {
            $checks[] = Check::warning(
                'git',
                'git is not installed: optimize keeps a copy of the originals instead of commits, and check cannot find changed files',
                'Install git.',
            );
        } else {
            $inside = new Process(['git', 'rev-parse', '--is-inside-work-tree'], (string) ($project?->root ?? $this->cwd));
            $inside->run();
            $checks[] = Check::ok('git', \trim($git->getOutput()) . (\trim($inside->getOutput()) === 'true'
                ? ', the project is a git work tree: every accepted step is a commit'
                : ', the project is not a git work tree: optimize keeps a copy of the originals in runs/'));
        }

        $checks[] = $this->cacheStore();
        if ($project === null) {
            return $checks;
        }

        $format = $this->commands->format ?? Formatter::detect($project->root);
        $checks[] = Check::info('formatter', $format === null || $format === 'none'
            ? 'none: changed lines are written as the printer makes them'
            : $format);
        $project->root->join('rector.php')->isFile() and $checks[] = Check::info(
            'rector.php',
            'the project has its own rector.php; opmin does not use it and runs its own Rector rules (see rector.* in opmin.yaml)',
        );

        return $checks;
    }

    /**
     * The store `cache.driver` gives under the PHP running opmin (not php.binary): `auto` depends on its
     * `pdo_sqlite`. An existing database is opened, a missing one is left for the first count to create.
     */
    private function cacheStore(): Check
    {
        try {
            $driver = $this->stores->driver($this->cache->driver);
        } catch (ConfigException $e) {
            return Check::error('cache', $e->getMessage(), 'Install pdo_sqlite for the PHP running opmin or set cache.driver: auto.');
        }

        $auto = $this->cache->driver === Schema\CacheDriver::Auto
            ? ($driver === Schema\CacheDriver::Sqlite ? ' (auto)' : ' (auto: the PHP running opmin has no pdo_sqlite)')
            : '';
        if ($driver === Schema\CacheDriver::Memory) {
            return Check::info('cache', 'memory: every run compiles and parses the project anew');
        }

        if ($driver === Schema\CacheDriver::Files) {
            return Check::info('cache', "files in {$this->cacheDir}{$auto}");
        }

        $store = new SqliteStore($this->cacheDir);
        if (!$store->file()->exists()) {
            return Check::ok('cache', "SQLite, {$store->file()}{$auto}: created by the first count");
        }

        $error = $store->error();

        return $error === null
            ? Check::ok('cache', "SQLite, {$store->file()}{$auto}")
            : Check::warning(
                'cache',
                "the SQLite database cannot be used, runs work without the cache: {$error}",
                "Delete {$store->file()} (the next run creates it anew), set cache.recreate_corrupt: true to let runs "
                . 'do it, or set cache.driver: files.',
            );
    }
}
