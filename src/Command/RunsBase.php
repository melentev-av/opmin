<?php

declare(strict_types=1);

namespace Opmin\Command;

use Internal\Path;
use Opmin\Module\Optimize\Workspace;
use Opmin\Module\Project\Layout;
use Opmin\Module\Runs\Run;
use Opmin\Module\Runs\RunIndex;

/**
 * The commands of `.opmin/runs/` next to the config (`runs`, `runs:show`, `runs:clean`).
 *
 * @internal
 */
abstract class RunsBase extends Base
{
    protected function runsDir(): Path
    {
        return $this->configDir->join(Layout::RUNS);
    }

    /**
     * @return list<Run> Newest first.
     */
    protected function runs(): array
    {
        return RunIndex::scan($this->runsDir(), Workspace::gitRoot($this->configDir) !== null);
    }

    /**
     * A path as the user finds it: relative to the directory of the config.
     */
    protected function relative(Path $path): string
    {
        $relative = (string) $path->tryRelative($this->configDir);

        return $relative === '' ? (string) $path : \str_replace('\\', '/', $relative);
    }
}
