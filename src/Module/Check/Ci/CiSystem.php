<?php

declare(strict_types=1);

namespace Opmin\Module\Check\Ci;

/**
 * CI systems `opmin init --ci=` writes the job of the CI guard for.
 *
 * @internal
 */
enum CiSystem: string
{
    /**
     * A workflow of its own, running the action of the opmin repository.
     */
    case Github = 'github';

    /**
     * A file of its own included from `.gitlab-ci.yml`: that file is usually shared, opmin never edits it.
     */
    case Gitlab = 'gitlab';

    /**
     * @return non-empty-string
     */
    public static function names(): string
    {
        return \implode(' | ', \array_map(static fn(self $s): string => $s->value, self::cases()));
    }

    /**
     * The file of the job, relative to the project root.
     *
     * @return non-empty-string
     */
    public function file(): string
    {
        return match ($this) {
            self::Github => '.github/workflows/opcodes.yml',
            self::Gitlab => '.gitlab/opmin.yml',
        };
    }
}
