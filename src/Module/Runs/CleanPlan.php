<?php

declare(strict_types=1);

namespace Opmin\Module\Runs;

/**
 * What {@see RunCleaner::plan()} decided.
 *
 * @internal
 */
final readonly class CleanPlan
{
    /**
     * @param list<Run> $delete Runs to delete, newest first.
     * @param list<Run> $backups Runs that would be deleted but hold the only copy of the code they changed.
     */
    public function __construct(
        public array $delete,
        public array $backups,
    ) {}
}
