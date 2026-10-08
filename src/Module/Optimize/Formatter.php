<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize;

use Internal\Path;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Project\Project;
use Opmin\Module\Tests\CommandLine;
use Symfony\Component\Process\Process;

/**
 * The project's code formatter, run on the files a step changed (brief, «Код-стайл после каждого
 * шага»). Rector prints changed code format-preserving; the formatter finishes the style.
 *
 * `commands.format` is the command with `{files}`; `null` detects one by the project's config:
 * `pint.json` (or Laravel Pint installed) → Pint, `.php-cs-fixer(.dist).php` → PHP-CS-Fixer,
 * `ecs.php` → ECS, `phpcs.xml(.dist)` → PHPCBF; `none` turns formatting off.
 *
 * @internal
 */
final class Formatter
{
    /**
     * @param list<string> $command Arguments with a `{files}` placeholder; empty — no formatter.
     */
    private function __construct(
        private readonly Project $project,
        private readonly PhpBinary $php,
        public readonly array $command,
        public readonly string $source,
    ) {}

    /**
     * @param string|null $configured `commands.format`.
     */
    public static function create(Project $project, PhpBinary $php, ?string $configured): self
    {
        if ($configured === 'none' || $configured === '') {
            return new self($project, $php, [], 'commands.format: none');
        }

        if ($configured !== null) {
            return new self($project, $php, CommandLine::split($configured), 'commands.format');
        }

        $detected = self::detect($project->root);

        return new self($project, $php, $detected === null ? [] : CommandLine::split($detected), $detected === null ? 'none found' : 'detected');
    }

    /**
     * The formatter command a project's config points to.
     */
    public static function detect(Path $root): ?string
    {
        $has = static fn(string $file): bool => $root->join($file)->isFile();
        $composer = (string) @\file_get_contents((string) $root->join('composer.json'));

        return match (true) {
            $has('pint.json') || ($has('vendor/bin/pint') && \str_contains($composer, '"laravel/pint"')) => 'vendor/bin/pint {files}',
            $has('.php-cs-fixer.php') || $has('.php-cs-fixer.dist.php') => 'vendor/bin/php-cs-fixer fix --quiet --path-mode=intersection {files}',
            $has('ecs.php') => 'vendor/bin/ecs check --fix --no-progress-bar {files}',
            $has('phpcs.xml') || $has('phpcs.xml.dist') || $has('.phpcs.xml') => 'vendor/bin/phpcbf {files}',
            default => null,
        };
    }

    public function enabled(): bool
    {
        return $this->command !== [];
    }

    /**
     * Human-readable command, for the run log.
     */
    public function describe(): string
    {
        return $this->enabled() ? \implode(' ', $this->command) . " ({$this->source})" : "no formatter ({$this->source})";
    }

    /**
     * Formats the files in place.
     *
     * @param list<Path> $files Absolute.
     * @throws \RuntimeException When the formatter cannot run at all.
     */
    public function format(array $files): void
    {
        if (!$this->enabled() || $files === []) {
            return;
        }

        $args = [];
        foreach ($this->command as $arg) {
            $arg === '{files}' ? \array_push($args, ...\array_map('strval', $files)) : $args[] = $arg;
        }

        \in_array('{files}', $this->command, true) or \array_push($args, ...\array_map('strval', $files));
        $process = new Process(CommandLine::withPhp($args, $this->php, $this->project->root), (string) $this->project->root, timeout: 300);
        $process->run();
        # Formatters exit with 1 when they fixed something (PHPCBF, ECS): only a failure to start counts.
        if ($process->getExitCode() === null || $process->getExitCode() >= 126) {
            throw new \RuntimeException(\sprintf(
                "The formatter `%s` failed (exit code %s):\n%s",
                \implode(' ', $this->command),
                (string) $process->getExitCode(),
                \trim($process->getErrorOutput() . $process->getOutput()),
            ));
        }
    }
}
