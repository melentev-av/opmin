<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;

/**
 * Stage B: the LLM (Claude Code skill) stage.
 *
 * @internal
 */
#[InflectableConfig]
final class Llm
{
    /** @var positive-int */
    #[ConfigKey('llm.top_n', 'Number of functions with the most opcodes to rewrite')]
    public int $topN = 20;

    /** @var positive-int */
    #[ConfigKey('llm.attempts_per_function', 'Rewrite attempts per function')]
    public int $attemptsPerFunction = 3;
}
