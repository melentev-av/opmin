<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize\Review;

/**
 * An answer of `opmin optimize --review` to one change (brief, «Читаемость», interactive mode).
 *
 * @internal
 */
enum Decision: string
{
    /**
     * Apply the change.
     */
    case Yes = 'y';

    /**
     * Roll it back and never propose it again (`opmin.baseline.yaml`).
     */
    case No = 'n';

    /**
     * Apply it and every later change of the same rule in this run without asking.
     */
    case All = 'a';

    /**
     * Roll it back and end the run after this step; `--resume` continues.
     */
    case Quit = 'q';
}
