<?php

declare(strict_types=1);

namespace Opmin\Module\Check;

/**
 * Output formats of `opmin check`.
 *
 * @internal
 */
enum Format: string
{
    case Table = 'table';
    case Json = 'json';

    /**
     * Workflow commands: annotations on the lines of the PR.
     */
    case Github = 'github';

    /**
     * Code Quality report (JSON).
     */
    case Gitlab = 'gitlab';
    case Checkstyle = 'checkstyle';

    /**
     * @return non-empty-string
     */
    public static function names(): string
    {
        return \implode(' | ', \array_map(static fn(self $f): string => $f->value, self::cases()));
    }
}
