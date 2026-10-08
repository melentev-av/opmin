<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Skill;

use Internal\Path;
use Opmin\Info;
use Opmin\Module\Skill\Skill;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Skill::class)]
final class SkillTest
{
    private string $dir = '';

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-skill-' . \bin2hex(\random_bytes(4));
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function theInstalledSkillCarriesTheVersionOfOpmin(): void
    {
        $skills = Path::create($this->dir);
        Assert::same(Skill::installedVersion($skills), null);

        $file = Skill::install($skills);

        Assert::same((string) $file, $this->dir . '/opcode-minimize/SKILL.md');
        Assert::same(Skill::installedVersion($skills), Info::version());
        $content = (string) \file_get_contents((string) $file);
        Assert::string($content)->contains("name: opcode-minimize\n")->contains('`opmin --version` must print `' . Info::version() . '`')->notContains('{{version}}');
        \file_put_contents((string) $file, "---\nname: opcode-minimize\n---\nOld.\n");
        Assert::same(Skill::installedVersion($skills), 'unknown');
    }
}
