<?php

declare(strict_types=1);

namespace Opmin\Module\Lint;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Project\Project;
use Opmin\Module\Tests\CommandLine;
use Symfony\Component\Process\Process;

/**
 * Level 1 of verification, static analysis: the project's PHPStan (`commands.phpstan`) under
 * `php.binary`, with `phpVersion` set to `php.target`. The rule is "no new errors": the result after
 * a change is compared with the result before it ({@see PhpStanResult::newErrors()}).
 *
 * The project's config (`phpstan.neon`, `phpstan.neon.dist`, `phpstan.dist.neon`) is included in a
 * generated one that adds `phpVersion`; without a config the level is 0. A command with its own
 * `-c`/`--configuration` is run as is.
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
     */
    public function __construct(
        private Project $project,
        private PhpBinary $php,
        private ?string $command,
        private ?string $phpTarget,
        private Path $workDir,
        private int $timeoutSeconds = 1800,
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
        $config = null;
        if (!self::hasConfigOption($args)) {
            $config = $this->config();
            $args[] = '--configuration=' . (string) $config;
            $this->projectConfig() === null && !self::hasOption($args, '--level', '-l') and $args[] = '--level=0';
        }

        try {
            $process = new Process(
                [...CommandLine::withPhp($args, $this->php, $this->project->root), ...\array_map(strval(...), $paths)],
                (string) $this->project->root,
                ['XDEBUG_MODE' => 'off'],
                null,
                $this->timeoutSeconds,
            );
            $process->run();

            return self::parse($process->getOutput(), $process->getErrorOutput());
        } finally {
            $config === null or FS::removeFile($config);
        }
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

    private function config(): Path
    {
        $config = $this->workDir->join('phpstan-opmin-' . \bin2hex(\random_bytes(4)) . '.neon');
        $neon = '';
        $project = $this->projectConfig();
        $project === null or $neon .= "includes:\n    - " . (string) \json_encode((string) $project, \JSON_UNESCAPED_SLASHES) . "\n";
        if ($this->phpTarget !== null && \preg_match('/^(\d+)\.(\d+)/', $this->phpTarget, $m) === 1) {
            $neon .= "parameters:\n    phpVersion: " . ((int) $m[1] * 10000 + (int) $m[2] * 100) . "\n";
        }

        \file_put_contents((string) $config, $neon === '' ? "parameters: []\n" : $neon);

        return $config;
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
