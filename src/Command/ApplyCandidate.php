<?php

declare(strict_types=1);

namespace Opmin\Command;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Apply a rewritten function proposed by the LLM stage and verify it.
 *
 * @internal
 */
#[AsCommand(
    name: 'apply-candidate',
    description: 'Apply a rewritten function proposed by the LLM stage and verify it',
)]
final class ApplyCandidate extends NotImplemented
{
    protected const string STAGE = 'M4';
}
