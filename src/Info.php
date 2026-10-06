<?php

declare(strict_types=1);

namespace Opmin;

/**
 * Application basic information provider.
 *
 * @internal
 */
final class Info
{
    /** @var non-empty-string Application name */
    public const NAME = 'opmin';

    /** @var non-empty-string Absolute path to the root directory */
    public const ROOT_DIR = __DIR__ . '/..';

    /** @var non-empty-string URL of the JSON Schema of `opmin.yaml` */
    public const SCHEMA_URL = 'https://raw.githubusercontent.com/melentev-av/opmin/master/resources/opmin.schema.json';

    /** @var non-empty-string Default version identifier if version file is not available */
    private const VERSION = 'experimental';

    /**
     * Returns the current application version.
     *
     * Version is retrieved from the release-please manifest `resources/version.json`
     * or falls back to the default value.
     *
     * @return non-empty-string The application version string
     */
    public static function version(): string
    {
        /** @var non-empty-string|null $cache */
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $fileContent = @\file_get_contents(self::ROOT_DIR . '/resources/version.json');

        if ($fileContent === false) {
            return $cache = self::VERSION;
        }

        /** @var mixed $version */
        $version = \json_decode($fileContent, true)['.'] ?? null;

        return $cache = \is_string($version) && $version !== ''
            ? $version
            : self::VERSION;
    }
}
