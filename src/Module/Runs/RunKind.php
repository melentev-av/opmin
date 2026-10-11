<?php

declare(strict_types=1);

namespace Opmin\Module\Runs;

/**
 * What wrote a directory of `.opmin/runs/` (the `kind` of `opmin runs --format=json`).
 *
 * @internal
 */
enum RunKind: string
{
    /**
     * `opmin optimize`: `state.json`.
     */
    case Optimize = 'optimize';

    /**
     * A session of the LLM stage: `llm.json`.
     */
    case Llm = 'llm';

    /**
     * `opmin verify`: only `counterexamples/`.
     */
    case Verify = 'verify';

    /**
     * A run of a git package copied from its clone: `package.json`; it cannot be resumed here.
     */
    case Package = 'package';

    /**
     * No state, no report: a run that died before it saved anything (or one still starting).
     */
    case Broken = 'broken';
}
