<?php

declare(strict_types=1);

namespace Opmin\Module\Skill;

use Internal\Path;
use Opmin\Info;
use Opmin\Module\Common\FileSystem\FS;

/**
 * The Claude Code skill `opcode-minimize` that ships inside opmin (brief, «Поставка», п. 4): the
 * LLM stage. The installed copy carries the version of opmin it was written for, and the skill
 * checks that `opmin --version` matches.
 *
 * @internal
 */
final class Skill
{
    public const NAME = 'opcode-minimize';
    private const SOURCE = Info::ROOT_DIR . '/resources/skills/' . self::NAME . '/SKILL.md';
    private const VERSION_MARK = '/<!-- opmin-skill-version: (\S+) -->/';

    /**
     * SKILL.md for the running opmin.
     */
    public static function render(): string
    {
        $source = @\file_get_contents(self::SOURCE);
        $source === false and throw new \RuntimeException('The skill is missing in this build of opmin: ' . self::SOURCE);

        return \str_replace('{{version}}', Info::version(), $source);
    }

    /**
     * `~/.claude/skills`.
     */
    public static function globalDir(): Path
    {
        $home = \getenv('HOME');
        \is_string($home) && $home !== '' or $home = \getenv('USERPROFILE');
        \is_string($home) && $home !== '' or throw new \RuntimeException('Cannot find the home directory: HOME is not set.');

        return Path::create($home)->join('.claude', 'skills');
    }

    /**
     * Directory of the skill under a `skills` directory.
     */
    public static function dir(Path $skillsDir): Path
    {
        return $skillsDir->join(self::NAME);
    }

    /**
     * Version of the skill installed under a `skills` directory: null when it is not installed,
     * `unknown` when the file has no version mark.
     */
    public static function installedVersion(Path $skillsDir): ?string
    {
        $content = @\file_get_contents((string) self::dir($skillsDir)->join('SKILL.md'));
        if ($content === false) {
            return null;
        }

        return \preg_match(self::VERSION_MARK, $content, $m) === 1 ? $m[1] : 'unknown';
    }

    /**
     * Writes the skill of the running opmin under a `skills` directory.
     *
     * @return Path The written SKILL.md.
     */
    public static function install(Path $skillsDir): Path
    {
        $dir = self::dir($skillsDir);
        FS::mkdir((string) $dir);
        $file = $dir->join('SKILL.md');
        \file_put_contents((string) $file, self::render());

        return $file;
    }
}
