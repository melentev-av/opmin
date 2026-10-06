<?php

declare(strict_types=1);

namespace Opmin\Module\Common\Internal\Attribute;

/**
 * Maps a property to a key of `opmin.yaml`.
 *
 * The key is a dotted path into the YAML document (`verification.seed`). The same key can always be
 * overridden by the environment variable derived from it (`OPMIN_VERIFICATION_SEED`) and by the
 * `--set=verification.seed=7` CLI option, so a key never needs explicit {@see Env} or
 * {@see InputOption} attributes just to be overridable.
 *
 * @internal
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ConfigKey implements ConfigAttribute
{
    /**
     * @param non-empty-string $path Dotted path of the key in `opmin.yaml`.
     * @param non-empty-string $description One-line description, used for the JSON Schema and the
     *        comments `opmin init` writes.
     * @param bool $list For `array` properties: the value is a list of strings. When false, an
     *        `array` property is a free-form map (e.g. rule FQCN => rule options).
     */
    public function __construct(
        public string $path,
        public string $description,
        public bool $list = false,
    ) {}

    /**
     * Name of the environment variable that overrides the key.
     *
     * @return non-empty-string
     */
    public function envName(): string
    {
        return 'OPMIN_' . \strtoupper(\str_replace('.', '_', $this->path));
    }
}
