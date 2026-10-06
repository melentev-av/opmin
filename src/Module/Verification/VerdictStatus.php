<?php

declare(strict_types=1);

namespace Opmin\Module\Verification;

/**
 * Outcome of the differential test of one function.
 *
 * @internal
 */
enum VerdictStatus: string
{
    /**
     * No difference found and the original's branches are covered: the change is accepted.
     */
    case Equivalent = 'diff-tested';

    /**
     * The versions behave differently on an input: the change is rejected.
     */
    case Mismatch = 'mismatch';

    /**
     * Behavior is not proven (low coverage, nondeterminism, side effects, cannot be called).
     */
    case Unverified = 'unverified';

    /**
     * Not tested at all (`eval`, `include`): the function is excluded from optimization.
     */
    case Skipped = 'skipped';
}
