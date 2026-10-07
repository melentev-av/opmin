<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize;

/**
 * Stands for the LLM stage where a step names its rule: in reports, commit messages and ignore marks
 * (`@opmin-ignore llm`, `#[\Opmin\Ignore(rules: ['llm'])]`).
 *
 * @internal
 */
final class LlmCandidate
{
    public static function alias(): string
    {
        return 'llm';
    }
}
