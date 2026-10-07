<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;

/**
 * Git package mode (`opmin optimize <git-url> --ref=<tag>`): the container the foreign code runs in.
 *
 * @internal
 */
#[InflectableConfig]
final class Package
{
    /** @var non-empty-string */
    #[ConfigKey('package.docker_image', 'Image for git packages; {version} — opmin version, {php} — the PHP minor the package allows')]
    public string $dockerImage = 'ghcr.io/melentev-av/opmin:{version}-php{php}';

    /** @var non-empty-string|null */
    #[ConfigKey('package.workspace', 'Where the clone is made; null — ~/.cache/opmin/packages with Docker (the Docker VM shares home), the temp directory without')]
    public ?string $workspace = null;

    /** @var non-empty-string */
    #[ConfigKey('package.memory', 'Memory limit of the container (docker --memory)')]
    public string $memory = '4g';

    /** @var positive-int|null */
    #[ConfigKey('package.cpus', 'CPU limit of the container (docker --cpus); null — all cores of the Docker host')]
    public ?int $cpus = null;
}
