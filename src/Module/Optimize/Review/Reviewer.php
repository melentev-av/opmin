<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize\Review;

/**
 * Asks the user about a change that passed every check (`opmin optimize --review`).
 *
 * @internal
 */
interface Reviewer
{
    public function review(Change $change): Decision;
}
