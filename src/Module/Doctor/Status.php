<?php

declare(strict_types=1);

namespace Opmin\Module\Doctor;

/**
 * Outcome of one check of `opmin doctor`.
 *
 * @internal
 */
enum Status: string
{
    /**
     * Works.
     */
    case Ok = 'ok';

    /**
     * Works, but slower or with less checking than it could.
     */
    case Warning = 'warning';

    /**
     * `count`/`optimize` cannot work until this is fixed: `doctor` exits with 1.
     */
    case Error = 'error';

    /**
     * Not a problem: what opmin found and will use.
     */
    case Info = 'info';

    /**
     * Not checked: an earlier check it depends on failed.
     */
    case Skipped = 'skipped';
}
