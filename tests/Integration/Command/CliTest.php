<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Command;

use Opmin\Command\Init;
use Opmin\Command\NotImplemented;
use Opmin\Command\SkillInstall;
use Opmin\Command\SkillUpdate;
use Opmin\Info;
use Opmin\Module\Config\ConfigLoader;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Runs `bin/opmin` as a separate process in a temporary project directory.
 */
#[Test]
#[Covers(Init::class)]
#[Covers(NotImplemented::class)]
#[Covers(SkillInstall::class)]
#[Covers(SkillUpdate::class)]
final class CliTest
{
    private string $dir;

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = \sys_get_temp_dir() . '/opmin-cli-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir);
    }

    #[AfterTest]
    public function removeProject(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function listsAllCommands(): void
    {
        [$code, $out] = $this->opmin('list');

        Assert::same($code, 0);
        foreach (['init', 'count', 'diff', 'optimize', 'llm:targets', 'llm:context', 'apply-candidate', 'llm:finish', 'baseline', 'check', 'doctor', 'self-update', 'skill:install', 'skill:update'] as $command) {
            Assert::string($out)->contains("  {$command} ");
        }
    }

    public function initWritesLoadableConfigAndIgnoresCache(): void
    {
        \mkdir($this->dir . '/.git');

        [$code] = $this->opmin('init', '--no-interaction');

        Assert::same($code, 0);
        Assert::string((string) \file_get_contents($this->dir . '/opmin.yaml'))
            ->startsWith('# yaml-language-server: $schema=');
        Assert::array(ConfigLoader::loadFile($this->dir . '/opmin.yaml'))->hasKeys('verification.seed', 'cache.dir');
        Assert::same(\file_get_contents($this->dir . '/.gitignore'), "/.opmin-cache/\n");
    }

    public function initKeepsExistingGitignoreEntry(): void
    {
        \file_put_contents($this->dir . '/.gitignore', "/vendor/\n.opmin-cache/\n");

        $this->opmin('init', '--no-interaction');

        Assert::same(\file_get_contents($this->dir . '/.gitignore'), "/vendor/\n.opmin-cache/\n");
    }

    public function initRefusesToOverwriteWithoutFlag(): void
    {
        \file_put_contents($this->dir . '/opmin.yaml', "paths: [lib]\n");

        [$code] = $this->opmin('init', '--no-interaction');
        [$overwriteCode] = $this->opmin('init', '--no-interaction', '--overwrite');

        Assert::same($code, 1);
        Assert::same($overwriteCode, 0);
        Assert::string((string) \file_get_contents($this->dir . '/opmin.yaml'))->contains('paths: [src]');
    }

    public function initWorksWhenExistingConfigIsBroken(): void
    {
        \file_put_contents($this->dir . '/opmin.yaml', "unknown: 1\n");

        [$code] = $this->opmin('init', '--no-interaction', '--overwrite');

        Assert::same($code, 0);
    }

    public function unknownConfigKeyFailsWithExitCode2(): void
    {
        \file_put_contents($this->dir . '/opmin.yaml', "verificaton:\n  seed: 1\n");

        [$code, , $err] = $this->opmin('count');

        Assert::same($code, 2);
        Assert::string($err)->contains('Unknown config key `verificaton` (opmin.yaml)');
        Assert::string($err)->notContains('ConfigException.php');
    }

    public function invalidEnvironmentValueFailsBeforeWork(): void
    {
        [$code, , $err] = $this->opminWithEnv(['OPMIN_VERIFICATION_SEED' => 'x'], 'count');

        Assert::same($code, 2);
        Assert::string($err)->ignoringWhitespace(lineBreaks: true)->contains('env OPMIN_VERIFICATION_SEED');
    }

    public function distConfigIsUsedWhenMainIsAbsent(): void
    {
        \file_put_contents($this->dir . '/opmin.yaml.dist', "bogus: 1\n");

        [$code, , $err] = $this->opmin('count');

        Assert::same($code, 2);
        Assert::string($err)->contains('(opmin.yaml.dist)');
    }

    public function initPutsTheSkillIntoTheProject(): void
    {
        [$code, $out] = $this->opmin('init', '--no-interaction');
        $skill = $this->dir . '/.claude/skills/opcode-minimize/SKILL.md';

        Assert::same($code, 0);
        Assert::string($out)->contains('Claude Code skill');
        Assert::string((string) \file_get_contents($skill))->contains('<!-- opmin-skill-version: ' . Info::version() . ' -->');
        \exec('rm -rf ' . \escapeshellarg($this->dir . '/.claude'));
        [$noSkill] = $this->opmin('init', '--no-interaction', '--overwrite', '--no-skill');
        Assert::same($noSkill, 0);
        Assert::false(\file_exists($skill));
    }

    public function skillIsInstalledAndUpdatedLocallyAndGlobally(): void
    {
        $home = ['HOME' => $this->dir . '/home'];
        $local = $this->dir . '/.claude/skills/opcode-minimize/SKILL.md';
        $global = $this->dir . '/home/.claude/skills/opcode-minimize/SKILL.md';

        [$update] = $this->opmin('skill:update');
        [$install, $installOut] = $this->opmin('skill:install');
        [$again, $againOut] = $this->opmin('skill:install');
        \file_put_contents($local, "<!-- opmin-skill-version: 0.0.1 -->\n");
        [$other, $otherOut] = $this->opmin('skill:install');
        [$updated, $updatedOut] = $this->opmin('skill:update');
        [$globalCode] = $this->opminWithEnv($home, 'skill:install', '--global');

        Assert::same([$update, $install, $again, $other, $updated, $globalCode], [1, 0, 0, 1, 0, 0]);
        Assert::string($installOut)->contains('is installed');
        Assert::string($againOut)->contains('is installed already');
        Assert::string($otherOut)->ignoringWhitespace(lineBreaks: true)->contains('The skill 0.0.1 is installed')->contains('skill:update');
        Assert::string($updatedOut)->contains('0.0.1 → ' . Info::version());
        Assert::string((string) \file_get_contents($local))->contains('opmin-skill-version: ' . Info::version());
        Assert::true(\is_file($global));
    }

    public function notImplementedCommandFails(): void
    {
        [$code, $out] = $this->opmin('doctor');

        Assert::same($code, 1);
        Assert::string($out)->contains('not implemented yet (planned for M7)');
    }

    /**
     * @return array{int, string, string} Exit code, stdout, stderr.
     */
    private function opmin(string ...$args): array
    {
        return $this->opminWithEnv([], ...$args);
    }

    /**
     * @param array<string, string> $env
     * @return array{int, string, string} Exit code, stdout, stderr.
     */
    private function opminWithEnv(array $env, string ...$args): array
    {
        $command = [\PHP_BINARY, \dirname(__DIR__, 3) . '/bin/opmin', '--no-ansi', ...$args];
        $process = \proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            $env + \getenv(),
        );
        \assert(\is_resource($process));

        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [\proc_close($process), $out, $err];
    }
}
