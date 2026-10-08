<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;
use Opmin\Module\Common\Internal\Attribute\InputOption;

/**
 * Which files of the project are analyzed, and which opmin it expects.
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
    #[ConfigKey('exclude', 'Directories and files to skip; a name without a slash matches at any depth, wildcards allowed', list: true)]
    #[InputOption('exclude')]
    public array $exclude = ['vendor', 'tests', '*Test.php'];

    /** @var non-empty-string|null */
    #[ConfigKey('requires', 'opmin version the project expects (e.g. "^1.2"; .opmin-version works too); null — any')]
    public ?string $requires = null;
}
