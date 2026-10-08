<?php

declare(strict_types=1);

namespace Opmin\Module\Check;

/**
 * What `opmin check` found about one function.
 *
 * @internal
 */
enum FindingKind: string
{
    /**
     * Opcodes grew by more than `check.tolerance`: the check fails.
     */
    case Grown = 'grown';

    /**
     * A new function above `check.max_ops_new_function`: a warning.
     */
    case NewOverLimit = 'new_over_limit';

    /**
     * Opcodes decreased: the baseline should be updated.
     */
    case Decreased = 'decreased';

    /**
     * Not in the baseline, within the limit.
     */
    case New = 'new';

    /**
     * In the baseline, gone from the code (removed or renamed).
     */
    case Removed = 'removed';

    public function severity(): Severity
    {
        return match ($this) {
            self::Grown => Severity::Error,
            self::NewOverLimit => Severity::Warning,
            self::Decreased, self::New, self::Removed => Severity::Info,
        };
    }
}
