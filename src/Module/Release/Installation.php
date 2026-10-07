<?php

declare(strict_types=1);

namespace Opmin\Module\Release;

use Internal\Path;
use Opmin\Info;

/**
 * How the running opmin is installed: the static binary, the PHAR, or sources (a git checkout or a
 * composer dependency) — and which file `self-update` replaces.
 *
 * @internal
 */
final readonly class Installation
{
    public const BINARY = 'binary';
    public const PHAR = 'phar';
    public const SOURCES = 'sources';

    /**
     * @param self::BINARY|self::PHAR|self::SOURCES $kind
     * @param Path|null $file The binary or the PHAR; null for sources.
     */
    public function __construct(
        public string $kind,
        public ?Path $file,
    ) {}

    public static function current(): self
    {
        $phar = \Phar::running(false);
        if ($phar === '') {
            return new self(self::SOURCES, null);
        }

        # micro (static-php-cli) runs the PHAR glued to its own executable.
        return \PHP_SAPI === 'micro'
            ? new self(self::BINARY, Path::create(\PHP_BINARY !== '' ? \PHP_BINARY : $phar))
            : new self(self::PHAR, Path::create($phar));
    }

    /**
     * The command line that starts this opmin again: what runs Rector and other internal entry points in a
     * subprocess (micro cannot run any script but its own, so the PHAR and the binary run themselves).
     *
     * @return non-empty-list<string>
     */
    public function command(): array
    {
        return match ($this->kind) {
            self::BINARY => [(string) $this->file],
            self::PHAR => [\PHP_BINARY, (string) $this->file],
            default => [\PHP_BINARY, Info::ROOT_DIR . '/bin/opmin'],
        };
    }

    /**
     * Release asset that replaces this installation.
     *
     * @return non-empty-string
     * @throws ReleaseException For sources.
     */
    public function asset(string $version, string $target): string
    {
        return match ($this->kind) {
            self::BINARY => Platform::archive($version, $target),
            self::PHAR => Release::PHAR,
            self::SOURCES => throw new ReleaseException(
                'This opmin runs from sources: update it with git or composer. self-update replaces the static binary or the PHAR.',
            ),
        };
    }
}
