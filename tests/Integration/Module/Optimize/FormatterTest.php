<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Module\Optimize;

use Internal\Path;
use Opmin\Module\Optimize\Formatter;
use Opmin\Module\Project\Project;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Formatter::class)]
final class FormatterTest
{
    private string $dir = '';

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-formatter-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/vendor/bin', 0777, true);
    }

    #[AfterTest]
    public function removeProject(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    #[DataSet(['pint.json', 'vendor/bin/pint {files}'], 'Pint')]
    #[DataSet(['.php-cs-fixer.dist.php', 'vendor/bin/php-cs-fixer fix --quiet --path-mode=intersection {files}'], 'PHP-CS-Fixer')]
    #[DataSet(['ecs.php', 'vendor/bin/ecs check --fix --no-progress-bar {files}'], 'ECS')]
    #[DataSet(['phpcs.xml.dist', 'vendor/bin/phpcbf {files}'], 'PHPCBF')]
    public function detectsTheFormatterByItsConfig(string $config, string $command): void
    {
        \touch($this->dir . '/' . $config);

        Assert::same(Formatter::detect(Path::create($this->dir)), $command);
    }

    public function detectsLaravelPintWithoutConfig(): void
    {
        \touch($this->dir . '/vendor/bin/pint');
        \file_put_contents($this->dir . '/composer.json', '{"require-dev": {"laravel/pint": "^1.0"}}');

        Assert::same(Formatter::detect(Path::create($this->dir)), 'vendor/bin/pint {files}');
    }

    public function noConfigNoFormatter(): void
    {
        $formatter = Formatter::create($this->project(), TestPhp::binary(), null);

        Assert::false($formatter->enabled());
        Assert::same($formatter->describe(), 'no formatter (none found)');
        Assert::false(Formatter::create($this->project(), TestPhp::binary(), 'none')->enabled());
    }

    public function runsTheFormatterWithPhpBinaryOnTheGivenFiles(): void
    {
        # A "formatter" that upper-cases the files it gets.
        \file_put_contents($this->dir . '/vendor/bin/fmt', "#!/usr/bin/env php\n<?php\nforeach (array_slice(\$argv, 2) as \$f) { file_put_contents(\$f, strtoupper(file_get_contents(\$f))); }\n");
        \file_put_contents($this->dir . '/a.php', 'abc');
        \file_put_contents($this->dir . '/b.php', 'def');
        $formatter = Formatter::create($this->project(), TestPhp::binary(), 'vendor/bin/fmt --mode {files}');

        $formatter->format([Path::create($this->dir . '/a.php')]);

        Assert::same(\file_get_contents($this->dir . '/a.php'), 'ABC');
        Assert::same(\file_get_contents($this->dir . '/b.php'), 'def');
        Assert::same($formatter->describe(), 'vendor/bin/fmt --mode {files} (commands.format)');
    }

    public function aFormatterThatCannotStartFails(): never
    {
        $formatter = Formatter::create($this->project(), TestPhp::binary(), 'no-such-formatter-binary {files}');

        Expect::exception(\RuntimeException::class)->withMessageContaining('no-such-formatter-binary');

        $formatter->format([Path::create($this->dir . '/a.php')]);
    }

    private function project(): Project
    {
        return new Project(Path::create($this->dir), true, null);
    }
}
