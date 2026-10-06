<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;
use Opmin\Module\Common\Internal\Attribute\InputOption;

/**
 * Which files of the project are analyzed.
 *
 * @internal
 */
#[InflectableConfig]
final class Project
{
    /** @var list<non-empty-string> */
    #[ConfigKey('paths', 'Directories and files to analyze, relative to the project root', list: true)]
    public array $paths = ['src'];

    /** @var list<non-empty-string> */
    #[ConfigKey('exclude', 'Directories and files to skip', list: true)]
    #[InputOption('exclude')]
    public array $exclude = ['vendor', 'tests'];
}
