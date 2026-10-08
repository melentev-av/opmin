<?php

declare(strict_types=1);

namespace Opmin\Module\Release;

use Internal\Path;
use Opmin\Module\Config\Exception\ConfigException;

/**
 * The opmin version a project pins: `requires` in `opmin.yaml`, or a `.opmin-version` file next to it
 * (or in the current directory). A global binary of another version stops with the command that installs
 * the right one, instead of producing counts and rewrites the project's CI would not reproduce.
 *
 * @internal
 */
final class ProjectVersion
{
    public const FILE = '.opmin-version';

    /**
     * @param string $running {@see \Opmin\Info::version()}; a build from sources (not `x.y.z`) is never checked.
     * @param non-empty-string|null $requires `requires` of the config.
     * @param Path $dir Directory of the config file, or the current directory.
     * @throws ConfigException When the running version does not match.
     */
    public static function check(string $running, ?string $requires, Path $dir): void
    {
        if (\preg_match('/^\d+\.\d+\.\d+/', $running) !== 1) {
            return;
        }

        $source = 'requires in opmin.yaml';
        if ($requires === null) {
            $file = $dir->join(self::FILE);
            $content = $file->isFile() ? \trim((string) \file_get_contents((string) $file)) : '';
            if ($content === '') {
                return;
            }

            $requires = $content;
            $source = (string) $file;
        }

        try {
            $constraint = VersionConstraint::parse($requires);
        } catch (\InvalidArgumentException $e) {
            throw new ConfigException(\rtrim($e->getMessage(), '.') . " ({$source}).", previous: $e);
        }

        if ($constraint->allows($running)) {
            return;
        }

        $exact = $constraint->exact();
        throw new ConfigException(\sprintf(
            'This project expects opmin %s (%s), but opmin %s is running. Install it: opmin self-update --to=%s',
            $requires,
            $source,
            $running,
            $exact ?? '<a version matching ' . $requires . '>',
        ));
    }
}
