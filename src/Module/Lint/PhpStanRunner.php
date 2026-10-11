<?php

declare(strict_types=1);

namespace Opmin\Module\Lint;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Project\Layout;
use Opmin\Module\Project\Project;
use Opmin\Module\Tests\CommandLine;
use Symfony\Component\Process\Process;

/**
 * Level 1 of verification, static analysis: the project's PHPStan (`commands.phpstan`) under
 * `php.binary`, with `phpVersion` set to `php.target`. The rule is "no new errors": the result after
 * a change is compared with the result before it ({@see PhpStanResult::newErrors()}).
 *
 * The project's config (`phpstan.neon`, `phpstan.neon.dist`, `phpstan.dist.neon`) is included in a
 * generated one that adds `phpVersion` and `tmpDir`; without a config the level is 0. A command with
 * its own `-c`/`--configuration` is run as is.
 *
 * The generated config is named by a hash of its content and kept between calls: PHPStan keys its
 * compiled DI container by the paths of the configs, so a new name on every call compiled a new
 * container (~170 ms) and left it on disk. Its `tmpDir` is under the cache dir of opmin, so the
 * containers go away with `.opmin` instead of piling up in the system temp directory.
 *
 * @internal
 */
final readonly class PhpStanRunner
{
    private const CONFIGS = ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'];

    /**
     * @param non-empty-string|null $command `commands.phpstan`; null — PHPStan is not used.
     * @param non-empty-string|null $phpTarget `php.target` (`8.3`).
     * @param positive-int $timeoutSeconds
     * @param Path|null $tmpDir PHPStan's `tmpDir` (its compiled containers); null — `.opmin/cache/phpstan`
     *        of the project. A relative one resolves against the project root.
     */
    public function __construct(
        private Project $project,
        private PhpBinary $php,
        private ?string $command,
        private ?string $phpTarget,
        private Path $workDir,
        private int $timeoutSeconds = 1800,
        private ?Path $tmpDir = null,
    ) {}

    public static function parse(string $json, string $stderr = ''): PhpStanResult
    {
        /** @var mixed $report */
        $report = \json_decode(\trim($json), true);
        if (!\is_array($report) || !\is_array($report['files'] ?? null)) {
            return new PhpStanResult([], false, \trim($json . "\n" . $stderr));
        }

        $errors = [];
        /** @var array<string, array{messages?: list<array<string, mixed>>}> $files */
        $files = $report['files'];
        foreach ($files as $file => $data) {
            foreach ($data['messages'] ?? [] as $message) {
                $errors[] = [
                    'file' => (string) $file,
                    'message' => (string) ($message['message'] ?? ''),
                    'identifier' => (string) ($message['identifier'] ?? ''),
                    'line' => (int) ($message['line'] ?? 0),
                ];
            }
        }

        /** @var list<string> $general */
        $general = \is_array($report['errors'] ?? null) ? $report['errors'] : [];
        foreach ($general as $message) {
            $errors[] = ['file' => '', 'message' => (string) $message, 'identifier' => 'general', 'line' => 0];
        }

        return new PhpStanResult($errors, true, $stderr);
    }

    /**
     * Whether the configured PHPStan exists in the project.
     */
    public function available(): bool
    {
        if ($this->command === null) {
            return false;
        }

        $args = CommandLine::split($this->command);
        if ($args === []) {
            return false;
        }

        $binary = Path::create($args[0]);
        $binary->isAbsolute() or $binary = $this->project->root->join($args[0]);

        return $binary->isFile();
    }

    /**
     * @param list<Path> $paths Files or directories to analyse (absolute).
     */
    public function analyse(array $paths): PhpStanResult
    {
        if (!$this->available() || $this->command === null) {
            return new PhpStanResult([], false, 'PHPStan is not available.');
        }

        FS::mkdir((string) $this->workDir);
        $args = CommandLine::split($this->command);
        if (!self::hasConfigOption($args)) {
            $config = $this->config();
            $args[] = '--configuration=' . (string) $config;
            $this->projectConfig() === null && !self::hasOption($args, '--level', '-l') and $args[] = '--level=0';
        }

        $process = new Process(
            [...CommandLine::withPhp($args, $this->php, $this->project->root), ...\array_map(strval(...), $paths)],
            (string) $this->project->root,
            ['XDEBUG_MODE' => 'off'],
            null,
            $this->timeoutSeconds,
        );
        $process->run();

        return self::parse($process->getOutput(), $process->getErrorOutput());
    }

    /**
     * The generated config in the work dir: `phpstan-opmin-<hash of the content>.neon`. The same
     * content gives the same path, so PHPStan reuses its container, and concurrent runs write the
     * same bytes (atomically, through a rename) or find the file already there.
     */
    public function config(): Path
    {
        $neon = '';
        $project = $this->projectConfig();
        $project === null or $neon .= "includes:\n    - " . self::neonString((string) $project) . "\n";
        $neon .= "parameters:\n";
        if ($this->phpTarget !== null && \preg_match('/^(\d+)\.(\d+)/', $this->phpTarget, $m) === 1) {
            $neon .= '    phpVersion: ' . ((int) $m[1] * 10000 + (int) $m[2] * 100) . "\n";
        }

        // Absolute: PHPStan resolves a relative tmpDir against the directory of this config. The
        // project's config is included above, and the including file wins, so this tmpDir replaces
        // the project's one.
        $neon .= '    tmpDir: ' . self::neonString((string) $this->tmpDir()) . "\n";

        $config = $this->workDir->join('phpstan-opmin-' . \substr(\sha1($neon), 0, 12) . '.neon');
        $config->isFile() or FS::replace($config, $neon);

        return $config;
    }

    private static function neonString(string $value): string
    {
        return (string) \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param list<string> $args
     */
    private static function hasConfigOption(array $args): bool
    {
        return self::hasOption($args, '--configuration', '-c');
    }

    /**
     * @param list<string> $args
     */
    private static function hasOption(array $args, string $long, string $short): bool
    {
        foreach ($args as $arg) {
            if ($arg === $long || $arg === $short || \str_starts_with($arg, $long . '=') || (\str_starts_with($arg, $short) && \strlen($arg) > 2 && $arg[2] !== '-')) {
                return true;
            }
        }

        return false;
    }

    private function tmpDir(): Path
    {
        $dir = $this->tmpDir ?? Path::create(Layout::CACHE . '/phpstan');

        return $dir->isAbsolute() ? $dir : $this->project->root->join((string) $dir);
    }

    private function projectConfig(): ?Path
    {
        foreach (self::CONFIGS as $name) {
            $path = $this->project->root->join($name);
            if ($path->isFile()) {
                return $path;
            }
        }

        return null;
    }
}
