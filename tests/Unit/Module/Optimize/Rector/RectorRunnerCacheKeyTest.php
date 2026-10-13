<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Optimize\Rector;

use Opmin\Module\Optimize\Rector\RectorRunner;
use Opmin\Module\Optimize\Rector\RuleSpec;
use Opmin\Rector\Rule\ExtractRepeatedPropertyFetchRector;
use Opmin\Rector\Rule\FullyQualifyGlobalCallsRector;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The key of Rector's per-rule cache: survives a new run directory, not a new opmin or new data.
 */
#[Test]
#[Covers(RectorRunner::class)]
final class RectorRunnerCacheKeyTest
{
    private string $dir = '';

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = \sys_get_temp_dir() . '/opmin-rector-key-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/run-a', recursive: true);
        \mkdir($this->dir . '/run-b');
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function opminVersionIsPartOfTheKey(): void
    {
        $rule = new RuleSpec(FullyQualifyGlobalCallsRector::class, [FullyQualifyGlobalCallsRector::STYLE => 'auto']);

        $old = RectorRunner::cacheKey($rule, '8.3', '0.4.0');
        $new = RectorRunner::cacheKey($rule, '8.3', '0.5.0');

        Assert::true($old !== $new);
    }

    public function sameDataInAnotherRunDirectoryKeepsTheKey(): void
    {
        $a = $this->fqRule('run-a', '{"functions":["strlen"]}', '{"symbols":[]}');
        $b = $this->fqRule('run-b', '{"functions":["strlen"]}', '{"symbols":[]}');

        $keyA = RectorRunner::cacheKey($a, '8.3', '1.0.0');
        $keyB = RectorRunner::cacheKey($b, '8.3', '1.0.0');

        Assert::same($keyA, $keyB);
    }

    public function effectsFileEntersByContent(): void
    {
        $rule = fn(string $run, string $json): RuleSpec => new RuleSpec(ExtractRepeatedPropertyFetchRector::class, [
            ExtractRepeatedPropertyFetchRector::EFFECTS => $this->write($run . '/effects.json', $json),
        ]);

        $a = RectorRunner::cacheKey($rule('run-a', '{"closure_writes":[]}'), '8.3', '1.0.0');
        $b = RectorRunner::cacheKey($rule('run-b', '{"closure_writes":[]}'), '8.3', '1.0.0');
        $other = RectorRunner::cacheKey($rule('run-b', '{"closure_writes":["p"]}'), '8.3', '1.0.0');

        Assert::same($a, $b);
        Assert::true($b !== $other);
    }

    public function otherSymbolsChangeTheKey(): void
    {
        $a = $this->fqRule('run-a', '{"functions":["strlen"]}', '{"symbols":[]}');
        $b = $this->fqRule('run-b', '{"functions":["strlen","count"]}', '{"symbols":[]}');

        $keyA = RectorRunner::cacheKey($a, '8.3', '1.0.0');
        $keyB = RectorRunner::cacheKey($b, '8.3', '1.0.0');

        Assert::true($keyA !== $keyB);
    }

    public function otherShadowsChangeTheKey(): void
    {
        $a = $this->fqRule('run-a', '{"functions":["strlen"]}', '{"symbols":[]}');
        $b = $this->fqRule('run-b', '{"functions":["strlen"]}', '{"symbols":{"App":["strlen"]}}');

        $keyA = RectorRunner::cacheKey($a, '8.3', '1.0.0');
        $keyB = RectorRunner::cacheKey($b, '8.3', '1.0.0');

        Assert::true($keyA !== $keyB);
    }

    public function otherPhpTargetChangesTheKey(): void
    {
        $rule = new RuleSpec(FullyQualifyGlobalCallsRector::class);

        $keyA = RectorRunner::cacheKey($rule, '8.3', '1.0.0');
        $keyB = RectorRunner::cacheKey($rule, '8.4', '1.0.0');

        Assert::true($keyA !== $keyB);
    }

    private function write(string $file, string $content): string
    {
        \file_put_contents("{$this->dir}/{$file}", $content);

        return "{$this->dir}/{$file}";
    }

    private function fqRule(string $run, string $symbols, string $shadows): RuleSpec
    {
        \file_put_contents("{$this->dir}/{$run}/symbols.json", $symbols);
        \file_put_contents("{$this->dir}/{$run}/shadows.json", $shadows);

        return new RuleSpec(FullyQualifyGlobalCallsRector::class, [
            FullyQualifyGlobalCallsRector::STYLE => 'auto',
            FullyQualifyGlobalCallsRector::SHADOWS => "{$this->dir}/{$run}/shadows.json",
            FullyQualifyGlobalCallsRector::SYMBOLS => "{$this->dir}/{$run}/symbols.json",
        ], custom: true);
    }
}
