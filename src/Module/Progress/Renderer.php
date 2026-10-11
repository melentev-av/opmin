<?php

declare(strict_types=1);

namespace Opmin\Module\Progress;

/**
 * Shows the progress on stderr.
 *
 * @internal
 */
interface Renderer extends Listener
{
    /**
     * Takes the line redrawn in place off the screen: called before anything else writes to stderr
     * (a prompt, a message, the summary). The next event draws it again.
     */
    public function clear(): void;
}
