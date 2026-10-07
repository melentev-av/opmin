<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Command;

use Opmin\Command\Baseline;
use Opmin\Command\Check;
use Opmin\Module\Check\ChangedFiles;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * `opmin baseline` and `opmin check` as processes on a git project with a feature branch: growth
 * fails, a decrease asks for a baseline update, another PHP refuses to compare, `--all` looks at
 * unchanged files too, and keys of closures do not depend on lines.
 */
#[Test]
#[Covers(Baseline::class)]
#[Covers(Check::class)]
#[Covers(ChangedFiles::class)]
final class CheckTest
{
    private const TEXT = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App;

        final class Text
        {
            public function size(string $s): int
            {
                return \strlen($s);
            }

            public function doubled(array $a): array
            {
                return \array_map(static fn(int $x): int => $x * 2, $a);
            }
        }

        PHP;
    private const MATH = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App;

        function half(int $x): int
        {
            return \intdiv($x, 2);
        }

        PHP;

    private string $dir = '';

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-check-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/src', 0777, true);
        \file_put_contents($this->dir . '/composer.json', '{"require": {"php": ">=8.1"}, "autoload": {"psr-4": {"App\\\\": "src/"}}}');
        \file_put_contents($this->dir . '/src/Text.php', self::TEXT);
        \file_put_contents($this->dir . '/src/math.php', self::MATH);
        \file_put_contents($this->dir . '/.gitignore', "/.opmin-cache/\n");

        $this->git('init', '-q');
        $this->git('checkout', '-q', '-b', 'main');
        [$code, $out, $err] = $this->opmin('baseline');
        Assert::same($code, 0, $out . $err);
        $this->commit('init');
        $this->git('checkout', '-q', '-b', 'feature');
    }

    #[AfterTest]
    public function removeProject(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function baselineIsDeterministic(): void
    {
        $first = (string) \file_get_contents($this->dir . '/opmin.baseline.json');

        [$code] = $this->opmin('baseline');

        Assert::same($code, 0);
        Assert::same((string) \file_get_contents($this->dir . '/opmin.baseline.json'), $first);
        Assert::string($first)->contains('"App\\\\Text::doubled::{closure:1}": {');
        Assert::string($first)->contains('"php": "' . TestPhp::binary()->version . '"');
    }

    public function growthFailsWithAnAnnotation(): void
    {
        $this->edit('src/Text.php', 'return \strlen($s);', 'return strlen($s);');

        [$code, $out, $err] = $this->opmin('check', '--base=main');
        [$githubCode, $github] = $this->opmin('check', '--base=main', '--format=github');

        Assert::same($code, 1, $out . $err);
        Assert::string($out)->contains('grown');
        Assert::string($out)->contains('App\Text::size');
        Assert::string($err)->contains('Opcodes grew');
        Assert::same($githubCode, 1);
        Assert::string($github)->contains('::error file=src/Text.php,line=9,title=opmin%3A grown::App\Text::size: opcodes grew');
    }

    public function suggestNamesTheRuleWithoutTouchingTheProject(): void
    {
        $this->edit('src/Text.php', 'return \strlen($s);', 'return strlen($s);');
        $before = (string) \file_get_contents($this->dir . '/src/Text.php');

        [$code, $out, $err] = $this->opmin('check', '--base=main', '--suggest', '--format=json');

        Assert::same($code, 1, $out . $err);
        /** @var array{findings: list<array{function: string, suggestion: array{rule: string, gain: int}|null}>} $report */
        $report = \json_decode($out, true, flags: \JSON_THROW_ON_ERROR);
        Assert::same($report['findings'][0]['function'], 'App\Text::size');
        Assert::same($report['findings'][0]['suggestion']['rule'] ?? null, 'Opmin\Rector\Rule\FullyQualifyGlobalCallsRector');
        Assert::same((string) \file_get_contents($this->dir . '/src/Text.php'), $before);
        Assert::same(\glob($this->dir . '/.opmin-cache/tmp/suggest-*') ?: [], []);
    }

    public function decreaseAsksToUpdateTheBaselineAndUpdatesIt(): void
    {
        $this->edit('src/math.php', 'return \intdiv($x, 2);', 'return $x >> 1;');

        [$code, , $err] = $this->opmin('check', '--base=main');
        [$updateCode, , $updateErr] = $this->opmin('check', '--base=main', '--update-baseline');
        [$againCode, , $againErr] = $this->opmin('check', '--base=main');

        Assert::same($code, 0, $err);
        Assert::string($err)->contains('1 decreased');
        Assert::string($err)->contains('opmin check --update-baseline');
        Assert::same($updateCode, 0, $updateErr);
        Assert::string($updateErr)->contains('Updated 1 function(s) in opmin.baseline.json');
        Assert::string($this->git('diff', '--', 'opmin.baseline.json'))->contains('"App\\\\half": {');
        Assert::same($againCode, 0);
        Assert::string($againErr)->contains('no changes');
    }

    public function baselineOfAnotherPhpIsNotCompared(): void
    {
        $baseline = (string) \file_get_contents($this->dir . '/opmin.baseline.json');
        \file_put_contents($this->dir . '/opmin.baseline.json', \preg_replace('/"php": "[^"]+"/', '"php": "7.4.33"', $baseline));
        $this->edit('src/Text.php', 'return \strlen($s);', 'return strlen($s);');

        [$code, $out, $err] = $this->opmin('check', '--base=main');

        Assert::same($code, 2, $out . $err);
        Assert::string($err)->contains('taken with PHP 7.4.33');
        Assert::string($err)->contains('opmin baseline');
    }

    public function changedModeLooksOnlyAtChangedFilesAndAllAtEveryFile(): void
    {
        $baseline = (string) \file_get_contents($this->dir . '/opmin.baseline.json');
        # As if `half` had fewer opcodes when the baseline was taken: it "grew" in an unchanged file.
        \file_put_contents($this->dir . '/opmin.baseline.json', \preg_replace('/("App\\\\\\\\half": \{\s*"ops": )\d+/', '${1}0', $baseline));
        $this->commit('baseline');

        [$changedCode, , $changedErr] = $this->opmin('check', '--base=main');
        [$allCode, $allOut] = $this->opmin('check', '--all');

        Assert::same($changedCode, 0, $changedErr);
        Assert::string($changedErr)->contains('No PHP files to check');
        Assert::same($allCode, 1);
        Assert::string($allOut)->contains('App\half');
    }

    public function closureKeysDoNotDependOnLines(): void
    {
        $this->edit('src/Text.php', 'final class Text', "/**\n * Shifts every line.\n *\n */\nfinal class Text");

        [$code, , $err] = $this->opmin('check', '--base=main');

        Assert::same($code, 0, $err);
        Assert::string($err)->contains('3 function(s) of 1 file(s) in files changed since main checked against opmin.baseline.json, no changes.');
    }

    public function renamedFunctionsAndFilesAreReported(): void
    {
        $this->git('mv', 'src/math.php', 'src/numbers.php');
        $this->edit('src/numbers.php', 'function half(', 'function halve(');
        $this->commit('rename');

        [$code, $out, $err] = $this->opmin('check', '--base=main', '--format=json');

        Assert::same($code, 0, $out . $err);
        /** @var array{findings: list<array{kind: string, function: string, file: string}>} $report */
        $report = \json_decode($out, true, flags: \JSON_THROW_ON_ERROR);
        $findings = \array_map(static fn(array $f): string => "{$f['kind']} {$f['function']} {$f['file']}", $report['findings']);
        Assert::same($findings, ['new App\halve src/numbers.php', 'removed App\half src/math.php']);
    }

    public function missingBaselineIsAUsageError(): void
    {
        \unlink($this->dir . '/opmin.baseline.json');

        [$code, , $err] = $this->opmin('check', '--all');

        Assert::same($code, 2);
        Assert::string($err)->contains('Create it with `opmin baseline`');
    }

    private function edit(string $file, string $search, string $replace): void
    {
        $path = $this->dir . '/' . $file;
        $content = (string) \file_get_contents($path);
        Assert::string($content)->contains($search);
        \file_put_contents($path, \str_replace($search, $replace, $content));
    }

    private function commit(string $message): void
    {
        $this->git('add', '-A');
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', $message);
    }

    private function git(string ...$args): string
    {
        $out = [];
        \exec('cd ' . \escapeshellarg($this->dir) . ' && git ' . \implode(' ', \array_map('escapeshellarg', $args)) . ' 2>&1', $out, $code);
        Assert::same($code, 0, \implode("\n", $out));

        return \implode("\n", $out);
    }

    /**
     * @return array{int, string, string} Exit code, stdout, stderr with whitespace collapsed (the
     *         console style wraps messages).
     */
    private function opmin(string ...$args): array
    {
        $process = \proc_open(
            [\PHP_BINARY, __DIR__ . '/../../../bin/opmin', '--no-ansi', ...$args],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            \array_merge(\getenv(), ['OPMIN_PHP_BINARY' => TestPhp::path(), 'COLUMNS' => '200']),
        );
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \preg_replace('/\s+/', ' ', (string) \stream_get_contents($pipes[2]));

        return [\proc_close($process), $out, $err];
    }
}
