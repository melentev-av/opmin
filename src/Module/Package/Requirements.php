<?php

declare(strict_types=1);

namespace Opmin\Module\Package;

use Opmin\Module\Release\VersionConstraint;

/**
 * What a package's `composer.json` demands of PHP: `require.php` and the `ext-*` it needs.
 *
 * @internal
 */
final readonly class Requirements
{
    /** @var list<non-empty-string> PHP minors opmin has Docker images for, newest first. */
    public const IMAGE_MINORS = ['8.5', '8.4', '8.3', '8.2', '8.1'];

    /**
     * @param non-empty-string|null $php `require.php`.
     * @param list<non-empty-string> $extensions Lower-case names without `ext-`.
     */
    public function __construct(
        public ?string $php,
        public array $extensions,
    ) {}

    public static function fromComposerJson(string $json): self
    {
        /** @var mixed $composer */
        $composer = \json_decode($json, true);
        /** @var mixed $require */
        $require = \is_array($composer) ? ($composer['require'] ?? null) : null;
        \is_array($require) or $require = [];
        /** @var mixed $constraint */
        $constraint = $require['php'] ?? null;
        $php = \is_string($constraint) ? \trim($constraint) : '';
        $extensions = [];
        foreach (\array_keys($require) as $package) {
            $package = \strtolower((string) $package);
            $name = \substr($package, 4);
            \str_starts_with($package, 'ext-') && $name !== '' and $extensions[] = $name;
        }

        \sort($extensions);

        return new self($php === '' ? null : $php, $extensions);
    }

    /**
     * Whether a PHP version satisfies `require.php`; true when the constraint is missing or not readable.
     */
    public function allowsPhp(string $version): bool
    {
        $constraint = $this->constraint();

        return $constraint === null || $constraint->allows($version);
    }

    /**
     * The minors of the opmin images the package allows, newest first.
     *
     * @return list<non-empty-string>
     */
    public function imageMinors(): array
    {
        $constraint = $this->constraint();

        return \array_values(\array_filter(
            self::IMAGE_MINORS,
            static fn(string $minor): bool => $constraint === null || $constraint->allows("{$minor}.0") || $constraint->allows("{$minor}.99"),
        ));
    }

    /**
     * Extensions the package needs that the PHP lacks.
     *
     * @param list<string> $loaded `get_loaded_extensions()` of that PHP.
     * @return list<non-empty-string>
     */
    public function missingExtensions(array $loaded): array
    {
        $have = \array_map(static fn(string $e): string => \str_replace(' ', '-', \strtolower($e)), $loaded);

        return \array_values(\array_diff($this->extensions, $have));
    }

    private function constraint(): ?VersionConstraint
    {
        try {
            return $this->php === null ? null : VersionConstraint::parse($this->php);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
