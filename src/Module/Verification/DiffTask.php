<?php

declare(strict_types=1);

namespace Opmin\Module\Verification;

use Internal\Path;

/**
 * One function to verify: the file in two versions and where the project is.
 *
 * @internal
 */
final readonly class DiffTask
{
    /**
     * @param non-empty-string $key Function key.
     * @param Path $file Absolute path of the file (its real location; both versions are served under it).
     * @param non-empty-string $relative Path of the file relative to the project root.
     * @param Path|null $autoload The project's `vendor/autoload.php`; null — bare files.
     * @param array<non-empty-string, array{string, string}> $otherChanges Other files changed in the
     *        same step: real path => [original content, changed content].
     */
    public function __construct(
        public string $key,
        public Path $file,
        public string $relative,
        public string $original,
        public string $changed,
        public ?Path $autoload = null,
        public array $otherChanges = [],
    ) {}
}
