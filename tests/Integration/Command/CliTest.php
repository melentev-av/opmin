<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Command;

use Opmin\Command\Init;
use Opmin\Command\SkillInstall;
use Opmin\Command\SkillUpdate;
use Opmin\Info;
use Opmin\Module\Config\ConfigLoader;
use Opmin\Module\Config\Schema\TestRunner;
use Opmin\Tests\Integration\TestPhp;
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
        Assert::same(\file_get_contents($this->dir . '/.gitignore'), ".opmin/\n");
    }

    public function initWritesWhatItDetectsInTheProject(): void
    {
        \mkdir($this->dir . '/app');
        \file_put_contents($this->dir . '/composer.json', '{"require": {"php": "^8.2 || ^8.3"}, "require-dev": {"phpunit/phpunit": "^11"}}');
        \file_put_contents($this->dir . '/phpunit.xml', '<phpunit/>');
        \file_put_contents($this->dir . '/pint.json', '{}');

        # Without a php.binary that starts, composer.json gives the target.
        [$code, $out] = $this->opmin('init', '--no-interaction', '--no-skill', '--set=php.binary=opmin-no-such-php');

        Assert::same($code, 0);
        Assert::string($out)->contains("php.target: '8.2'")->contains('paths: [app]')->contains('tests.runner: phpunit');
        $values = ConfigLoader::loadFile($this->dir . '/opmin.yaml');
        Assert::same($values['php.target'] ?? null, '8.2');
        Assert::same($values['paths'] ?? null, ['app']);
        Assert::same($values['tests.runner'] ?? null, TestRunner::PhpUnit);
        Assert::same($values['commands.format'] ?? null, 'vendor/bin/pint {files}');
        Assert::same(\array_key_exists('commands.phpstan', $values) ? $values['commands.phpstan'] : 'missing', null);
    }

    public function initTakesTheTargetFromPhpBinary(): void
    {
        \file_put_contents($this->dir . '/composer.json', '{"require": {"php": ">=8.1"}}');

        [$code, $out] = $this->opmin('init', '--no-interaction', '--no-skill', '--set=php.binary=' . TestPhp::path());

        Assert::same($code, 0);
        Assert::string($out)->contains('php.binary ' . TestPhp::path());
        Assert::same(ConfigLoader::loadFile($this->dir . '/opmin.yaml')['php.target'] ?? null, TestPhp::minor());
    }

    public function initKeepsExistingGitignoreEntry(): void
    {
        \file_put_contents($this->dir . '/.gitignore', "/vendor/\n/.opmin\n");

        $this->opmin('init', '--no-interaction');

        Assert::same(\file_get_contents($this->dir . '/.gitignore'), "/vendor/\n/.opmin\n");
    }

    public function aProjectCommandIgnoresTheOpminDirectory(): void
    {
        \file_put_contents($this->dir . '/opmin.yaml', '');
        \file_put_contents($this->dir . '/.gitignore', "/vendor/\n");

        [, , $err] = $this->opmin('count');

        Assert::same(\file_get_contents($this->dir . '/.gitignore'), "/vendor/\n.opmin/\n");
        Assert::string($err)->ignoringWhitespace(lineBreaks: true)->contains('Added .opmin/ to .gitignore');
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

    public function aPinnedVersionStopsAnotherOpminButNotSelfUpdate(): void
    {
        \file_put_contents($this->dir . '/.opmin-version', "9.9.9\n");
        \file_put_contents($this->dir . '/opmin.yaml', '');

        [$code, , $err] = $this->opmin('count');
        [$updateCode, $updateOut] = $this->opminWithEnv(['HTTPS_PROXY' => 'http://127.0.0.1:9'], 'self-update');

        Assert::same($code, 2);
        Assert::string($err)->ignoringWhitespace(lineBreaks: true)
            ->contains('This project expects opmin 9.9.9')->contains('opmin self-update --to=9.9.9');
        Assert::same($updateCode, 1);
        Assert::string($updateOut)->ignoringWhitespace(lineBreaks: true)->contains('This opmin runs from sources');
    }

    public function selfUpdateRefusesToReplaceSourcesWithoutGoingToTheNetwork(): void
    {
        [$code, $out] = $this->opminWithEnv(['HTTPS_PROXY' => 'http://127.0.0.1:9'], 'self-update');

        Assert::same($code, 1);
        Assert::string($out)->ignoringWhitespace(lineBreaks: true)->contains('This opmin runs from sources');
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
        Assert::string($err)->ignoringWhitespace(lineBreaks: true)->contains('Unknown config key `verificaton` (opmin.yaml). Did you mean `verification`?');
        Assert::string($err)->notContains('ConfigException.php');
    }

    public function unknownOverrideNamesTheClosestKeyWithExitCode2(): void
    {
        \file_put_contents($this->dir . '/opmin.yaml', "verification:\n  seed: 1\n");

        [$code, , $err] = $this->opmin('count', '--set=seed=7');

        Assert::same($code, 2);
        Assert::string($err)->ignoringWhitespace(lineBreaks: true)->contains('Unknown config key `seed` (--set). Did you mean `verification.seed`?');
    }

    public function invalidEnvironmentValueFailsBeforeWork(): void
    {
        \file_put_contents($this->dir . '/opmin.yaml', '');
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

    public function initEndsWithTheNextStepsAndDoesNotRunDoctorWithoutATerminal(): void
    {
        \mkdir($this->dir . '/app');

        [$code, $out] = $this->opmin('init', '--no-interaction', '--no-skill');

        Assert::same($code, 0);
        Assert::string($out)
            ->contains('Next:')
            ->contains('opmin doctor ')
            ->contains('opmin count --top=10 ')
            ->contains('opmin optimize --dry-run app ')
            ->contains('.opmin/report_<ts>.md')
            ->notContains('PHP version:')
            ->notContains('doctor:');
    }

    public function initRunsDoctorOnTheWrittenConfig(): void
    {
        \mkdir($this->dir . '/src');
        \mkdir($this->dir . '/bin');
        \symlink(TestPhp::path(), $this->dir . '/bin/php');
        # No php.binary at init: the target comes from composer.json, the written php.binary is `php` (bin/php).
        \file_put_contents($this->dir . '/composer.json', '{"require": {"php": ">=8.0"}}');

        [$code, $out] = $this->opminWithEnv(
            ['PATH' => $this->dir . '/bin:' . (string) \getenv('PATH')],
            'init',
            '--no-interaction',
            '--no-skill',
            '--doctor',
            '--set=php.binary=opmin-no-such-php',
        );

        Assert::same($code, 0);
        Assert::string($out)
            ->contains('✔ config: ')
            ->contains('php.binary is PHP ' . TestPhp::binary()->version . ', php.target is 8.0')
            ->matchesRegex('/doctor: \d+ blocker\(s\)/')
            ->contains('Next:');
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
