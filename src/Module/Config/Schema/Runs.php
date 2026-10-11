<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;

/**
 * The run directories `.opmin/runs/<ts>/`.
 *
 * @internal
 */
#[InflectableConfig]
final class Runs
{
    /** @var non-negative-int|null */
    #[ConfigKey('runs.keep', 'After optimize and llm:targets create a run, delete the older finished runs beyond this many, as opmin runs:clean --keep does (interrupted runs, open LLM sessions and the only backups of code outside git stay); null — never delete')]
    public ?int $keep = null;
}
