<?php

declare(strict_types=1);

namespace Opmin\Module\Runs;

/**
 * Where a run is (the `state` of `opmin runs --format=json`).
 *
 * @internal
 */
enum RunStatus: string
{
    case Finished = 'finished';

    /**
     * An optimize run that did not finish: `opmin optimize --resume=<dir>` continues it.
     */
    case Interrupted = 'interrupted';

    /**
     * An LLM session `llm:finish` has not ended yet.
     */
    case Open = 'open';
    case Broken = 'broken';
}
